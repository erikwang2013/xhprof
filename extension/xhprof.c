/*
 *  Copyright (c) 2009 Facebook
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 *
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "php_xhprof.h"
#include "zend_extensions.h"
#include "trace.h"

#ifndef ZEND_WIN32
# include <sys/time.h>
# include <sys/resource.h>
# include <unistd.h>
#else
# include "win32/time.h"
# include "win32/getrusage.h"
# include "win32/unistd.h"
#endif
#include <stdlib.h>

#if HAVE_PCRE
#include "ext/pcre/php_pcre.h"
#endif

#if __APPLE__
#include <mach/mach_init.h>
#include <mach/mach_time.h>
#endif

#ifdef ZEND_WIN32
LARGE_INTEGER performance_frequency;
#endif

ZEND_DECLARE_MODULE_GLOBALS(xhprof)

/**
 * ****************************
 * STATIC FUNCTION DECLARATIONS
 * ****************************
 */

/* {{{ arginfo */
#if PHP_VERSION_ID >= 80000
# define XHPROF_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, type_hint, allow_null, default_value) \
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, type_hint, allow_null, default_value)
#else
/* PHP < 8 arginfo has no default value for parameters */
# define XHPROF_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, type_hint, allow_null, default_value) \
    ZEND_ARG_TYPE_INFO(pass_by_ref, name, type_hint, allow_null)
#endif

/* The enable functions return NULL on success, but false when profiling is
 * turned off (xhprof.profiler=0), so no return type can be declared without
 * turning that documented error path into a TypeError. */
ZEND_BEGIN_ARG_INFO_EX(arginfo_xhprof_enable, 0, 0, 0)
  XHPROF_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, flags, IS_LONG, 0, "0")
  XHPROF_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

/* Returns NULL (not an array) when profiling was never started */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_xhprof_disable, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO(arginfo_xhprof_sample_enable, 0)
ZEND_END_ARG_INFO()

/* Returns NULL (not an array) when profiling was never started */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_xhprof_sample_disable, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()
/* }}} */

/**
 * *********************
 * PHP EXTENSION GLOBALS
 * *********************
 */
/* List of functions implemented/exposed by xhprof */
zend_function_entry xhprof_functions[] = {
  PHP_FE(xhprof_enable, arginfo_xhprof_enable)
  PHP_FE(xhprof_disable, arginfo_xhprof_disable)
  PHP_FE(xhprof_sample_enable, arginfo_xhprof_sample_enable)
  PHP_FE(xhprof_sample_disable, arginfo_xhprof_sample_disable)
  {NULL, NULL, NULL}
};

/* Callback functions for the xhprof extension */
zend_module_entry xhprof_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
        STANDARD_MODULE_HEADER,
#endif
        "xhprof",                        /* Name of the extension */
        xhprof_functions,                /* List of functions exposed */
        PHP_MINIT(xhprof),               /* Module init callback */
        PHP_MSHUTDOWN(xhprof),           /* Module shutdown callback */
        PHP_RINIT(xhprof),               /* Request init callback */
        PHP_RSHUTDOWN(xhprof),           /* Request shutdown callback */
        PHP_MINFO(xhprof),               /* Module info callback */
#if ZEND_MODULE_API_NO >= 20010901
        XHPROF_VERSION,
#endif
        STANDARD_MODULE_PROPERTIES
};

PHP_INI_BEGIN()

/* output directory:
 * Currently this is not used by the extension itself.
 * But some implementations of iXHProfRuns interface might
 * choose to save/restore XHProf profiler runs in the
 * directory specified by this ini setting.
 */
PHP_INI_ENTRY("xhprof.output_dir", "", PHP_INI_ALL, NULL)

/*
 * collect_additional_info
 * Collect mysql_query, curl_exec internal info. The default is 0.
 */
STD_PHP_INI_ENTRY("xhprof.collect_additional_info", "0", PHP_INI_ALL, OnUpdateBool, collect_additional_info, zend_xhprof_globals, xhprof_globals)

/*
 * collect_files
 * Record the file (and declaration line) every profiled user function is
 * defined in; the map is attached to the profile data as "__files__" when
 * profiling stops. The default is 0: enabling it costs one hash lookup per
 * profiled call and grows every run file by the size of the map.
 */
STD_PHP_INI_ENTRY("xhprof.collect_files", "0", PHP_INI_ALL, OnUpdateBool, collect_files, zend_xhprof_globals, xhprof_globals)

/* sampling_interval:
 * Sampling interval to be used by the sampling profiler, in microseconds.
 */
#define STRINGIFY_(X) #X
#define STRINGIFY(X) STRINGIFY_(X)

STD_PHP_INI_ENTRY("xhprof.sampling_interval", STRINGIFY(XHPROF_DEFAULT_SAMPLING_INTERVAL), PHP_INI_ALL, OnUpdateLong, sampling_interval, zend_xhprof_globals, xhprof_globals)

/* sampling_depth:
 * Depth to trace call-chain by the sampling profiler
 */
STD_PHP_INI_ENTRY("xhprof.sampling_depth", STRINGIFY(INT_MAX), PHP_INI_ALL, OnUpdateLong, sampling_depth, zend_xhprof_globals, xhprof_globals)

/* profiler:
 * Master switch. When 0 the fcall observer / execute hooks are not installed
 * at all, so an idle request has exactly the same overhead as without the
 * extension loaded. xhprof_enable() and xhprof_sample_enable() then fail with
 * a warning instead of returning a profile that only contains "main()".
 */
STD_PHP_INI_ENTRY("xhprof.profiler", "1", PHP_INI_SYSTEM, OnUpdateBool, profiler, zend_xhprof_globals, xhprof_globals)

/* auto_enable:
 * Start hierarchical profiling at request startup, without calling
 * xhprof_enable() from the script. Requires xhprof.profiler=1.
 */
STD_PHP_INI_ENTRY("xhprof.auto_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, auto_enable, zend_xhprof_globals, xhprof_globals)

/* auto_enable_flags:
 * Flags used by xhprof.auto_enable (e.g. XHPROF_FLAGS_CPU|XHPROF_FLAGS_MEMORY)
 */
STD_PHP_INI_ENTRY("xhprof.auto_enable_flags", "0", PHP_INI_SYSTEM, OnUpdateLong, auto_enable_flags, zend_xhprof_globals, xhprof_globals)
PHP_INI_END()

/* Init module */
#ifdef COMPILE_DL_XHPROF
    ZEND_GET_MODULE(xhprof)
#endif

#ifdef ZTS
    ZEND_TSRMLS_CACHE_DEFINE();
#endif

/**
 * **********************************
 * PHP EXTENSION FUNCTION DEFINITIONS
 * **********************************
 */

/**
 * Start XHProf profiling in hierarchical mode.
 *
 * @param  long $flags  flags for hierarchical mode
 * @return void
 * @author kannan
 */
PHP_FUNCTION(xhprof_enable)
{
    zend_long xhprof_flags = 0;              /* XHProf flags */
    zval *optional_array = NULL;         /* optional array arg: for future use */

    /* "a!" accepts an array or null; anything else fails with a TypeError
     * instead of crashing in hp_get_ignored_functions_from_arg() */
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|la!", &xhprof_flags, &optional_array) == FAILURE) {
        return;
    }

    hp_get_ignored_functions_from_arg(optional_array);

    if (!hp_begin(XHPROF_MODE_HIERARCHICAL, xhprof_flags)) {
        /* Failing loudly beats handing out a profile that only contains the
         * fictitious "main()" frame */
        php_error_docref(NULL, E_WARNING, "profiling is disabled (xhprof.profiler=0)");
        RETURN_FALSE;
    }
}

/**
 * Stops XHProf from profiling in hierarchical mode anymore and returns the
 * profile info.
 *
 * @param  void
 * @return array  hash-array of XHProf's profile info
 * @author kannan, hzhao
 */
PHP_FUNCTION(xhprof_disable)
{
    if (XHPROF_G(enabled)) {
        hp_stop();
        hp_attach_file_map();
        RETURN_ZVAL(&XHPROF_G(stats_count), 1, 0);
    }
    /* else null is returned */
}

/**
 * Start XHProf profiling in sampling mode.
 *
 * @return void
 * @author cjiang
 */
PHP_FUNCTION(xhprof_sample_enable)
{
    zend_long xhprof_flags = 0;    /* XHProf flags */
    hp_get_ignored_functions_from_arg(NULL);

    if (!hp_begin(XHPROF_MODE_SAMPLED, xhprof_flags)) {
        php_error_docref(NULL, E_WARNING, "profiling is disabled (xhprof.profiler=0)");
        RETURN_FALSE;
    }
}

/**
 * Stops XHProf from profiling in sampling mode anymore and returns the profile
 * info.
 *
 * @param  void
 * @return array  hash-array of XHProf's profile info
 * @author cjiang
 */
PHP_FUNCTION(xhprof_sample_disable)
{
    if (XHPROF_G(enabled)) {
        hp_stop();
        RETURN_ZVAL(&XHPROF_G(stats_count), 1, 0);
    }
  /* else null is returned */
}

static void php_xhprof_init_globals(zend_xhprof_globals *xhprof_globals)
{
    xhprof_globals->enabled = 0;
    xhprof_globals->ever_enabled = 0;
    xhprof_globals->xhprof_flags = 0;
    xhprof_globals->entries = NULL;
    xhprof_globals->root = NULL;
    xhprof_globals->trace_callbacks = NULL;
    xhprof_globals->file_map = NULL;
    xhprof_globals->ignored_functions = NULL;
    xhprof_globals->sampling_interval = XHPROF_DEFAULT_SAMPLING_INTERVAL;
    xhprof_globals->sampling_depth = INT_MAX;

    /* REGISTER_INI_ENTRIES() overwrites these with the configured values */
    xhprof_globals->profiler = 1;
    xhprof_globals->auto_enable = 0;
    xhprof_globals->auto_enable_flags = 0;

    xhprof_globals->sample_buf = NULL;
    xhprof_globals->sample_buf_len = 0;

    ZVAL_UNDEF(&xhprof_globals->stats_count);

    /* no free hp_entry_t structures to start with */
    xhprof_globals->entry_free_list = NULL;

    int i;

    for (i = 0; i < XHPROF_FUNC_HASH_COUNTERS_SIZE; i++) {
        xhprof_globals->func_hash_counters[i] = 0;
    }

    if (xhprof_globals->sampling_interval < XHPROF_MINIMAL_SAMPLING_INTERVAL) {
        xhprof_globals->sampling_interval = XHPROF_MINIMAL_SAMPLING_INTERVAL;
    }
}

/**
 * Module init callback.
 *
 * @author cjiang
 */
PHP_MINIT_FUNCTION(xhprof)
{
#if defined(ZTS) && defined(COMPILE_DL_XHPROF)
    /* MINIT touches the module globals (the INI handlers below and the
     * xhprof.profiler check) before any RINIT has run on this thread, so the
     * static TSRMLS cache has to be primed here as well. No-op in NTS builds. */
    ZEND_TSRMLS_CACHE_UPDATE();
#endif

    ZEND_INIT_MODULE_GLOBALS(xhprof, php_xhprof_init_globals, NULL);

    REGISTER_INI_ENTRIES();

    hp_register_constants(INIT_FUNC_ARGS_PASSTHRU);

    /* The proxies and the fcall observer below are the only instrumentation
     * xhprof adds.  With xhprof.profiler=0 (PHP_INI_SYSTEM, so this decision
     * holds for the whole process) none of them is installed and profiling
     * cannot be started, which makes an idle request exactly as cheap as
     * running without the extension. */
    if (XHPROF_G(profiler)) {
        /* Replace zend_compile with our proxy */
        _zend_compile_file = zend_compile_file;
        zend_compile_file  = hp_compile_file;

        /* Replace zend_compile_string with our proxy */
        _zend_compile_string = zend_compile_string;
        zend_compile_string = hp_compile_string;

#if PHP_VERSION_ID >= 80000
        zend_observer_fcall_register(tracer_observer);
#else
        /* Replace zend_execute with our proxy */
        _zend_execute_ex = zend_execute_ex;
        zend_execute_ex  = hp_execute_ex;
#endif

        /* Replace zend_execute_internal with our proxy */
        _zend_execute_internal = zend_execute_internal;
        zend_execute_internal = hp_execute_internal;
    }

#if defined(DEBUG)
    /* To make it random number generator repeatable to ease testing. */
    srand(0);
#endif

#ifdef ZEND_WIN32
    QueryPerformanceFrequency(&performance_frequency); 
#endif
    return SUCCESS;
}

/**
 * Module shutdown callback.
 */
PHP_MSHUTDOWN_FUNCTION(xhprof)
{
    /* free any remaining items in the free list */
    hp_free_the_free_list();

    /* Mirror the xhprof.profiler gate in MINIT: nothing was installed if the
     * profiler is disabled, so nothing must be restored either */
    if (XHPROF_G(profiler)) {
#if PHP_VERSION_ID < 80000
        /* Remove proxies, restore the originals */
        zend_execute_ex       = _zend_execute_ex;
#endif
        zend_execute_internal = _zend_execute_internal;
        zend_compile_file     = _zend_compile_file;
        zend_compile_string   = _zend_compile_string;
    }

    UNREGISTER_INI_ENTRIES();

    return SUCCESS;
}

/**
 * Request init callback. Nothing to do yet!
 */
PHP_RINIT_FUNCTION(xhprof)
{
#if defined(ZTS) && defined(COMPILE_DL_XHPROF)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif

    XHPROF_G(timebase_conversion) = get_timebase_conversion();

    /* xhprof.auto_enable: start profiling without an explicit xhprof_enable()
     * call. hp_begin() is idempotent, so a manual xhprof_enable() afterwards
     * is a no-op and the INI configured flags win. With xhprof.profiler=0
     * hp_begin() refuses and nothing is profiled. */
    if (XHPROF_G(auto_enable)) {
        hp_begin(XHPROF_MODE_HIERARCHICAL, XHPROF_G(auto_enable_flags));
    }

    return SUCCESS;
}

/**
 * Request shutdown callback. Stop profiling and return.
 */
PHP_RSHUTDOWN_FUNCTION(xhprof)
{
    hp_end();
    return SUCCESS;
}

/**
 * Module info callback. Returns the xhprof version.
 */
PHP_MINFO_FUNCTION(xhprof)
{
    char level[32];
    char count[16];
    int ignored = 0;
    const char *mode;

    /* Only report a profiler level when the profiler ever ran in this
     * request; "n/a" otherwise. */
    if (XHPROF_G(ever_enabled)) {
        switch (XHPROF_G(profiler_level)) {
            case XHPROF_MODE_HIERARCHICAL:
                mode = "hierarchical";
                break;
            case XHPROF_MODE_SAMPLED:
                mode = "sampled";
                break;
            default:
                mode = "unknown";
                break;
        }
        snprintf(level, sizeof(level), "%d (%s)", XHPROF_G(profiler_level), mode);
    } else {
        snprintf(level, sizeof(level), "n/a");
    }

    /* Bounded by XHPROF_MAX_IGNORED_FUNCTIONS: short walk, no long chains */
    if (XHPROF_G(ignored_functions)) {
        while (ignored < XHPROF_MAX_IGNORED_FUNCTIONS
               && XHPROF_G(ignored_functions)->names[ignored] != NULL) {
            ignored++;
        }
    }
    snprintf(count, sizeof(count), "%d", ignored);

    php_info_print_table_start();
    php_info_print_table_header(2, "xhprof support", XHPROF_G(profiler) ? "enabled" : "disabled");
    php_info_print_table_row(2, "Version", XHPROF_VERSION);
    php_info_print_table_row(2, "Profiler enabled (this request)", XHPROF_G(enabled) ? "yes" : "no");
    php_info_print_table_row(2, "Profiler level", level);
    php_info_print_table_row(2, "Ignored functions", count);
    php_info_print_table_row(2, "Additional info callbacks", XHPROF_G(trace_callbacks) ? "registered" : "none");
    php_info_print_table_row(2, "Reusable entries on free list", XHPROF_G(entry_free_list) ? "available" : "none");
    php_info_print_table_end();
    DISPLAY_INI_ENTRIES();
}


/**
 * ***************************************************
 * COMMON HELPER FUNCTION DEFINITIONS AND LOCAL MACROS
 * ***************************************************
 */

static void hp_register_constants(INIT_FUNC_ARGS)
{
    REGISTER_LONG_CONSTANT("XHPROF_FLAGS_NO_BUILTINS",
                         XHPROF_FLAGS_NO_BUILTINS,
                         CONST_CS | CONST_PERSISTENT);

    REGISTER_LONG_CONSTANT("XHPROF_FLAGS_CPU",
                         XHPROF_FLAGS_CPU,
                         CONST_CS | CONST_PERSISTENT);

    REGISTER_LONG_CONSTANT("XHPROF_FLAGS_MEMORY",
                         XHPROF_FLAGS_MEMORY,
                         CONST_CS | CONST_PERSISTENT);
}

/**
 * Parse the list of ignored functions from the zval argument.
 *
 * @author mpal
 */
void hp_get_ignored_functions_from_arg(zval *args)
{
    if (args == NULL || Z_TYPE_P(args) != IS_ARRAY) {
        return;
    }

    zval *pzval = zend_hash_str_find(Z_ARRVAL_P(args), "ignored_functions", sizeof("ignored_functions") - 1);
    XHPROF_G(ignored_functions) = hp_ignored_functions_init(pzval);
}

void hp_ignored_functions_clear(hp_ignored_functions *functions)
{
    if (functions == NULL) {
        return;
    }

    hp_array_del(functions->names);
    functions->names = NULL;

    memset(functions->filter, 0, sizeof(functions->filter));
    efree(functions);
}

double get_timebase_conversion()
{
#if defined(__APPLE__)
    mach_timebase_info_data_t info;
    (void) mach_timebase_info(&info);

    return info.denom * 1000. / info.numer;
#endif

    return 1.0;
}

hp_ignored_functions *hp_ignored_functions_init(zval *values)
{
    hp_ignored_functions *functions;
    zend_string **names;
    uint32_t ix = 0;
    int count;

    /* Delete the array storing ignored function names */
    hp_ignored_functions_clear(XHPROF_G(ignored_functions));

    if (!values) {
        return NULL;
    }

    if (Z_TYPE_P(values) == IS_ARRAY) {
        HashTable *ht;
        zend_string *key;
        zval *val;
        int truncated = 0;

        ht = Z_ARRVAL_P(values);
        count = zend_hash_num_elements(ht);

        names = ecalloc(count + 1, sizeof(zend_string *));

        ZEND_HASH_FOREACH_STR_KEY_VAL(ht, key, val) {
            if (!key) {
                if (Z_TYPE_P(val) == IS_STRING && strcmp(Z_STRVAL_P(val), ROOT_SYMBOL) != 0) {
                    /* do not ignore "main" */
                    if (ix >= XHPROF_MAX_IGNORED_FUNCTIONS) {
                        /* names[] is only freed up to XHPROF_MAX_IGNORED_FUNCTIONS
                         * entries, so stop storing (and warn once) instead of
                         * leaking everything past the limit */
                        if (!truncated) {
                            php_error_docref(NULL, E_WARNING, "ignored_functions is limited to %d entries; extra entries are ignored", XHPROF_MAX_IGNORED_FUNCTIONS);
                            truncated = 1;
                        }
                        continue;
                    }
                    names[ix] = zend_string_init(Z_STRVAL_P(val), Z_STRLEN_P(val), 0);
                    ix++;
                }
            }
        } ZEND_HASH_FOREACH_END();
    } else if (Z_TYPE_P(values) == IS_STRING) {
        names = ecalloc(2, sizeof(zend_string *));
        if (strcmp(Z_STRVAL_P(values), ROOT_SYMBOL) != 0) {
            /* do not ignore "main" */
            names[0] = zend_string_init(Z_STRVAL_P(values), Z_STRLEN_P(values), 0);
            ix = 1;
        }
    } else {
        return NULL;
    }

    /* NULL terminate the array */
    names[ix] = NULL;

    functions = emalloc(sizeof(hp_ignored_functions));
    functions->names = names;

    memset(functions->filter, 0, sizeof(functions->filter));

    uint32_t i = 0;
    for (; names[i] != NULL; i++) {
        zend_ulong hash = ZSTR_HASH(names[i]);
        int idx = hash % XHPROF_MAX_IGNORED_FUNCTIONS;
        functions->filter[idx] = hash;
    }

    return functions;
}

/**
 * Initialize profiler state
 *
 * @author kannan, veeve
 */
void hp_init_profiler_state(int level)
{
    /* Setup globals */
    if (!XHPROF_G(ever_enabled)) {
        XHPROF_G(ever_enabled) = 1;
        XHPROF_G(entries) = NULL;
    }

    XHPROF_G(profiler_level) = (int)level;

    /* Init stats_count */
    if (Z_TYPE(XHPROF_G(stats_count)) != IS_UNDEF) {
        zval_ptr_dtor(&XHPROF_G(stats_count));
    }

    array_init(&XHPROF_G(stats_count));

    hp_init_trace_callbacks();
    hp_init_file_map(level);

    /* Call current mode's init cb */
    XHPROF_G(mode_cb).init_cb();
}

/**
 * Cleanup profiler state
 *
 * @author kannan, veeve
 */
void hp_clean_profiler_state()
{
    /* Call current mode's exit cb */
    XHPROF_G(mode_cb).exit_cb();

    /* Clear globals */
    if (Z_TYPE(XHPROF_G(stats_count)) != IS_UNDEF) {
        zval_ptr_dtor(&XHPROF_G(stats_count));
    }

    ZVAL_UNDEF(&XHPROF_G(stats_count));

    XHPROF_G(entries) = NULL;
    XHPROF_G(profiler_level) = 1;
    XHPROF_G(ever_enabled) = 0;

    if (XHPROF_G(trace_callbacks)) {
        zend_hash_destroy(XHPROF_G(trace_callbacks));
        FREE_HASHTABLE(XHPROF_G(trace_callbacks));
        XHPROF_G(trace_callbacks) = NULL;
    }

    if (XHPROF_G(file_map)) {
        zend_hash_destroy(XHPROF_G(file_map));
        FREE_HASHTABLE(XHPROF_G(file_map));
        XHPROF_G(file_map) = NULL;
    }

    if (XHPROF_G(root)) {
        zend_string_release(XHPROF_G(root));
        XHPROF_G(root) = NULL;
    }

    if (XHPROF_G(sample_buf)) {
        efree(XHPROF_G(sample_buf));
        XHPROF_G(sample_buf) = NULL;
        XHPROF_G(sample_buf_len) = 0;
    }

    /* Delete the array storing ignored function names */
    hp_ignored_functions_clear(XHPROF_G(ignored_functions));
    XHPROF_G(ignored_functions) = NULL;
}

/* Delimiter between two frames of a stack symbol */
#define    HP_STACK_DELIM        "==>"
#define    HP_STACK_DELIM_LEN    (sizeof(HP_STACK_DELIM) - 1)

/**
 * Length of the "@<recurse level>" suffix of a function name.
 * rlvl_hprof is a recursion depth, so it is never negative.
 */
static size_t hp_rlvl_len(int rlvl)
{
    zend_ulong value = (zend_ulong)rlvl;
    size_t len = 2;  /* '@' plus at least one digit */

    while (value >= 10) {
        len++;
        value /= 10;
    }

    return len;
}

/**
 * Report (at most once per process) that a symbol did not fit into its
 * buffer. Distinct long function names would otherwise be silently merged
 * into a single profile entry, so this must not go unnoticed.
 */
static void hp_warn_truncated_symbol(void)
{
    static int warned = 0;

    if (!warned) {
        warned = 1;
        php_error_docref(NULL, E_WARNING, "function symbol exceeds the profiling buffer and was truncated; profile entries may be merged");
    }
}

/**
 * Returns formatted function name
 *
 * @param  entry        hp_entry
 * @author veeve
 */
size_t hp_get_entry_name(hp_entry_t *entry, char *result_buf, size_t result_len)
{
    const char *name = ZSTR_VAL(entry->name_hprof);
    size_t name_len = ZSTR_LEN(entry->name_hprof);

    /* Add '@recurse_level' if required */
    if (entry->rlvl_hprof) {
        size_t suffix_len = hp_rlvl_len(entry->rlvl_hprof);

        if (result_len < name_len + suffix_len + 1) {
            hp_warn_truncated_symbol();
            /* NOTE:  Dont use snprintf's return val as it is compiler dependent */
            return (size_t)snprintf(result_buf, result_len, "%s@%d", name, entry->rlvl_hprof);
        }
#if PHP_VERSION_ID >= 80000
        /* zend_print_long_to_buf() writes the digits backwards ending at the
         * given pointer, so format them into the tail of the buffer and move
         * them right behind the "@" (memmove: the ranges may overlap) */
        char *tail = result_buf + result_len - 1;
        char *digits = zend_print_long_to_buf(tail, (zend_long)entry->rlvl_hprof);
        size_t digits_len = (size_t)(tail - digits);

        memcpy(result_buf, name, name_len);
        result_buf[name_len] = '@';
        memmove(result_buf + name_len + 1, digits, digits_len);
        result_buf[name_len + 1 + digits_len] = '\0';

        return name_len + 1 + digits_len;
#else
        /* PHP 7 has no zend_print_long_to_buf() in zend_operators.h */
        return (size_t)snprintf(result_buf, result_len, "%s@%d", name, entry->rlvl_hprof);
#endif
    }

    if (name_len >= result_len) {
        /* Keep snprintf's truncating semantics for undersized buffers, but
         * make the loss visible */
        hp_warn_truncated_symbol();
        if (result_len) {
            memcpy(result_buf, name, result_len - 1);
            result_buf[result_len - 1] = '\0';
        }
        return name_len;
    }

    /* Common case (no recursion): a plain copy, no format string parsing */
    memcpy(result_buf, name, name_len + 1);

    return name_len;
}


/**
 * Returns the number of bytes (excluding the terminating NUL) that
 * hp_get_function_stack() needs for the given stack level. Callers size
 * their buffer with this so that the symbol always fits: with a fixed size
 * buffer, two different long function names were truncated to the same
 * symbol and their profile entries were merged.
 *
 * @author kannan, veeve
 */
static size_t hp_get_function_stack_length(hp_entry_t *entry, int level)
{
    size_t ancestors_len;
    size_t len = ZSTR_LEN(entry->name_hprof);

    /* '@<recurse level>' suffix, if any */
    if (entry->rlvl_hprof) {
        len += hp_rlvl_len(entry->rlvl_hprof);
    }

    /* End recursion if we dont need deeper levels or we dont have any deeper
    * levels */
    if (!entry->prev_hprof || (level <= 1)) {
        return len;
    }

    /* Take care of all ancestors first */
    ancestors_len = hp_get_function_stack_length(entry->prev_hprof, level - 1);

    /* The delimiter is only written if the ancestors produced something */
    return ancestors_len ? ancestors_len + HP_STACK_DELIM_LEN + len : len;
}

/**
 * Build a caller qualified name for a callee.
 *
 * For example, if A() is caller for B(), then it returns "A==>B".
 * Recursive invokations are denoted with @<n> where n is the recursion
 * depth.
 *
 * For example, "foo==>foo@1", and "foo@2==>foo@3" are examples of direct
 * recursion. And  "bar==>foo@1" is an example of an indirect recursive
 * call to foo (implying the foo() is on the call stack some levels
 * above).
 *
 * @author kannan, veeve
 */
size_t hp_get_function_stack(hp_entry_t *entry, int level, char *result_buf, size_t result_len)
{
    size_t len = 0;

    /* End recursion if we dont need deeper levels or we dont have any deeper
    * levels */
    if (!entry->prev_hprof || (level <= 1)) {
        return hp_get_entry_name(entry, result_buf, result_len);
    }

    /* Take care of all ancestors first */
    len = hp_get_function_stack(entry->prev_hprof, level - 1, result_buf, result_len);

    /* The "+ 1" accounts for the NUL: strncat() appends at most
     * result_len - len - 1 bytes.  Callers size result_buf with
     * hp_get_function_stack_length(), so bailing out should not happen. */
    if (result_len < (len + HP_STACK_DELIM_LEN + 1)) {
        /* Insufficient result_buf. Bail out! */
        hp_warn_truncated_symbol();
        return len;
    }

    /* Add delimiter only if entry had ancestors */
    if (len) {
        strncat(result_buf + len, HP_STACK_DELIM, result_len - len);
        len += HP_STACK_DELIM_LEN;
    }

    /* Append the current function name */
    return len + hp_get_entry_name(entry, result_buf + len, result_len - len);
}

/**
 * Takes an input of the form /a/b/c/d/foo.php and returns
 * a pointer to one-level directory and basefile name
 * (d/foo.php) in the same string.
 */
static const char *hp_get_base_filename(const char *filename)
{
    const char *ptr;
    int   found = 0;

    if (!filename)
        return "";

    /* reverse search for "/" and return a ptr to the next char */
    for (ptr = filename + strlen(filename) - 1; ptr >= filename; ptr--) {
        if (*ptr == '/') {
            found++;
        }

        if (found == 2) {
            return ptr + 1;
        }
    }

    /* no "/" char found, so return the whole string */
    return filename;
}

/**
 * Free any items in the free list.
 */
static void hp_free_the_free_list()
{
    hp_entry_t *p = XHPROF_G(entry_free_list);
    hp_entry_t *cur;

    while (p) {
        cur = p;
        p = p->prev_hprof;
        free(cur);
    }
}

/**
 * Increment the count of the given stat with the given count
 * If the stat was not set before, inits the stat to the given count
 *
 * @param  zval *counts   Zend hash table pointer
 * @param  char *name     Name of the stat
 * @param  long  count    Value of the stat to incr by
 * @return void
 * @author kannan
 */
void hp_inc_count(zval *counts, char *name, zend_long count)
{
    HashTable *ht;
    zval *data, val;

    if (!counts) {
        return;
    }

    ht = HASH_OF(counts);

    if (!ht) {
        return;
    }

    data = zend_hash_str_find(ht, name, strlen(name));

    if (data) {
        ZVAL_LONG(data, Z_LVAL_P(data) + count);
    } else {
        ZVAL_LONG(&val, count);
        zend_hash_str_update(ht, name, strlen(name), &val);
    }

}

/**
 * Allocate the function-file map for this profiling session. Only
 * hierarchical runs with xhprof.collect_files=1 get one; when the map is
 * NULL, hp_record_function_file() is a no-op and no memory is spent on it.
 */
void hp_init_file_map(int level)
{
    /* Like stats_count, the map survives xhprof_disable() until the request
     * ends: drop any previous session's map so re-enabling always starts
     * clean and a session with collecting turned off gets none. */
    if (XHPROF_G(file_map)) {
        zend_hash_destroy(XHPROF_G(file_map));
        FREE_HASHTABLE(XHPROF_G(file_map));
        XHPROF_G(file_map) = NULL;
    }

    if (!XHPROF_G(collect_files) || level != XHPROF_MODE_HIERARCHICAL) {
        return;
    }

    ALLOC_HASHTABLE(XHPROF_G(file_map));

    if (!XHPROF_G(file_map)) {
        return;
    }

    /* values are "file:line" strings, keys are function names */
    zend_hash_init(XHPROF_G(file_map), 128, NULL, ZVAL_PTR_DTOR, 0);
}

/**
 * Remember where a profiled function is defined. Called on every profiled
 * call; the common case is a single hash probe that hits (a function's file
 * is recorded the first time it is seen). Internals and the load::/eval::
 * compile hooks have no definition file of their own and are skipped.
 */
void hp_record_function_file(zend_string *function_name, zend_function *func)
{
    zval file;

    if (XHPROF_G(file_map) == NULL || func == NULL ||
        func->type != ZEND_USER_FUNCTION || func->op_array.filename == NULL) {
        return;
    }

    if (zend_hash_exists(XHPROF_G(file_map), function_name)) {
        return;
    }

    ZVAL_STR(&file, strpprintf(0, "%s:%d", ZSTR_VAL(func->op_array.filename),
                               (int)func->op_array.line_start));
    zend_hash_add(XHPROF_G(file_map), function_name, &file);
}

/**
 * Attach the function-file map to the profile data as "__files__" before it
 * is returned by xhprof_disable(). The reporting layer strips the key again
 * in get_run(), so no report computation ever sees it.
 */
void hp_attach_file_map()
{
    zend_string *name;
    zval *file;
    zval files;

    if (XHPROF_G(file_map) == NULL ||
        zend_hash_num_elements(XHPROF_G(file_map)) == 0 ||
        Z_TYPE(XHPROF_G(stats_count)) != IS_ARRAY) {
        return;
    }

    array_init(&files);
    ZEND_HASH_FOREACH_STR_KEY_VAL(XHPROF_G(file_map), name, file) {
        if (name != NULL && file != NULL) {
            Z_TRY_ADDREF_P(file);
            zend_hash_add(Z_ARRVAL(files), name, file);
        }
    } ZEND_HASH_FOREACH_END();

    zend_hash_str_update(Z_ARRVAL(XHPROF_G(stats_count)), "__files__",
                         sizeof("__files__") - 1, &files);
}

/**
 * Truncates the given timeval to the nearest slot begin, where
 * the slot size is determined by intr
 *
 * @param  tv       Input timeval to be truncated in place
 * @param  intr     Time interval in microsecs - slot width
 * @return void
 * @author veeve
 */
void hp_trunc_time(struct timeval *tv, zend_ulong intr)
{
    zend_ulong time_in_micro;

    /* Convert to microsecs and trunc that first */
    time_in_micro = (tv->tv_sec * 1000000) + tv->tv_usec;
    time_in_micro /= intr;
    time_in_micro *= intr;

    /* Update tv */
    tv->tv_sec  = (time_in_micro / 1000000);
    tv->tv_usec = (time_in_micro % 1000000);
}

/**
 * Sample the stack. Add it to the stats_count global.
 *
 * @param  tv            current time
 * @param  entries       func stack as linked list of hp_entry_t
 * @return void
 * @author veeve
 */
void hp_sample_stack(hp_entry_t  **entries)
{
    char key[SCRATCH_BUF_LEN];
    size_t symbol_len;

    /* Build key */
    snprintf(key, sizeof(key), "%d.%06d", (uint32) XHPROF_G(last_sample_time).tv_sec, (uint32) XHPROF_G(last_sample_time).tv_usec);

    /* A fixed 512KB C stack buffer used to live here; use a per request heap
     * buffer instead, sized for the symbol that is about to be built (a fixed
     * buffer silently merged distinct long function names) */
    symbol_len = hp_get_function_stack_length(*entries, XHPROF_G(sampling_depth)) + 1;

    if (symbol_len > XHPROF_G(sample_buf_len)) {
        if (XHPROF_G(sample_buf)) {
            efree(XHPROF_G(sample_buf));
        }
        XHPROF_G(sample_buf) = emalloc(symbol_len);
        XHPROF_G(sample_buf_len) = symbol_len;
    }

    /* Init stats in the global stats_count hashtable */
    hp_get_function_stack(*entries, XHPROF_G(sampling_depth), XHPROF_G(sample_buf), symbol_len);

    add_assoc_string(&XHPROF_G(stats_count), key, XHPROF_G(sample_buf));
}

/**
 * Checks to see if it is time to sample the stack.
 * Calls hp_sample_stack() if its time.
 *
 * @param  entries        func stack as linked list of hp_entry_t
 * @param  last_sample    time the last sample was taken
 * @param  sampling_intr  sampling interval in microsecs
 * @return void
 * @author veeve
 */
void hp_sample_check(hp_entry_t **entries)
{
    /* Validate input */
    if (!entries || !(*entries)) {
        return;
    }

    /* See if its time to sample.  While loop is to handle a single function
    * taking a long time and passing several sampling intervals. */
    while ((cycle_timer() - XHPROF_G(last_sample_tsc)) > XHPROF_G(sampling_interval_tsc)) {
        /* bump last_sample_tsc */
        XHPROF_G(last_sample_tsc) += XHPROF_G(sampling_interval_tsc);

        /* bump last_sample_time - HAS TO BE UPDATED BEFORE calling hp_sample_stack */
        incr_us_interval(&XHPROF_G(last_sample_time), XHPROF_G(sampling_interval));

        /* sample the stack */
        hp_sample_stack(entries);
    }
}


/**
 * ***********************
 * High precision timer related functions.
 * ***********************
 */

static inline zend_ulong cycle_timer()
{
#if defined(__APPLE__) && defined(__MACH__)
    return mach_absolute_time() / XHPROF_G(timebase_conversion);
#elif defined(ZEND_WIN32)
    LARGE_INTEGER lt = {0};
    QueryPerformanceCounter(&lt);
    lt.QuadPart *= 1000000;
    lt.QuadPart /= performance_frequency.QuadPart;
    return lt.QuadPart;
#else
    struct timespec s;
    clock_gettime(CLOCK_MONOTONIC, &s);

    return s.tv_sec * 1000 * 1000 + s.tv_nsec / 1000;
#endif
}

/**
 * Get the current real CPU clock timer
 */
static zend_ulong cpu_timer()
{
#if defined(CLOCK_PROCESS_CPUTIME_ID)
    struct timespec s;
    clock_gettime(CLOCK_PROCESS_CPUTIME_ID, &s);

    return s.tv_sec * 1000 * 1000 + s.tv_nsec / 1000;
#else
    struct rusage ru;
    getrusage(RUSAGE_SELF, &ru);

    return (ru.ru_utime.tv_sec  + ru.ru_stime.tv_sec ) * 1000 * 1000 + (ru.ru_utime.tv_usec + ru.ru_stime.tv_usec);
#endif
}

/**
 * Incr time with the given microseconds.
 */
static void incr_us_interval(struct timeval *start, zend_ulong incr)
{
    incr += (start->tv_sec * 1000000 + start->tv_usec);
    start->tv_sec  = incr / 1000000;
    start->tv_usec = incr % 1000000;
}

/**
 * ***************************
 * XHPROF DUMMY CALLBACKS
 * ***************************
 */
void hp_mode_dummy_init_cb()
{

}

void hp_mode_dummy_exit_cb()
{

}

void hp_mode_dummy_beginfn_cb(hp_entry_t **entries, hp_entry_t *current)
{

}

void hp_mode_dummy_endfn_cb(hp_entry_t **entries)
{

}

/**
 * *********************************
 * XHPROF INIT MODULE CALLBACKS
 * *********************************
 */
/**
 * XHPROF_MODE_SAMPLED's init callback
 *
 * @author veeve
 */
void hp_mode_sampled_init_cb()
{
    /* The clamp in php_xhprof_init_globals() happens before REGISTER_INI_ENTRIES(),
     * so re-apply it here: a zero (or negative) interval would divide by zero in
     * hp_trunc_time() and spin forever in hp_sample_check() */
    if (XHPROF_G(sampling_interval) < XHPROF_MINIMAL_SAMPLING_INTERVAL) {
        XHPROF_G(sampling_interval) = XHPROF_MINIMAL_SAMPLING_INTERVAL;
    }

    /* Init the last_sample in tsc */
    XHPROF_G(last_sample_tsc) = cycle_timer();

    /* Find the microseconds that need to be truncated */
    gettimeofday(&XHPROF_G(last_sample_time), 0);
    hp_trunc_time(&XHPROF_G(last_sample_time), XHPROF_G(sampling_interval));

    /* Convert sampling interval to ticks */
    XHPROF_G(sampling_interval_tsc) = XHPROF_G(sampling_interval);
}


/**
 * ************************************
 * XHPROF BEGIN FUNCTION CALLBACKS
 * ************************************
 */

/**
 * XHPROF_MODE_HIERARCHICAL's begin function callback
 *
 * @author kannan
 */
void hp_mode_hier_beginfn_cb(hp_entry_t **entries, hp_entry_t  *current)
{
    /* Get start tsc counter */
    current->tsc_start = cycle_timer();

    /* Get CPU usage */
    if (XHPROF_G(xhprof_flags) & XHPROF_FLAGS_CPU) {
        current->cpu_start = cpu_timer();
    }

    /* Get memory usage */
    if (XHPROF_G(xhprof_flags) & XHPROF_FLAGS_MEMORY) {
        current->mu_start_hprof  = zend_memory_usage(0);
        current->pmu_start_hprof = zend_memory_peak_usage(0);
    }
}


/**
 * XHPROF_MODE_SAMPLED's begin function callback
 *
 * @author veeve
 */
void hp_mode_sampled_beginfn_cb(hp_entry_t **entries, hp_entry_t *current)
{
    /* See if its time to take a sample */
    hp_sample_check(entries);
}


/**
 * **********************************
 * XHPROF END FUNCTION CALLBACKS
 * **********************************
 */

/**
 * XHPROF_MODE_HIERARCHICAL's end function callback
 *
 * @author kannan
 */
void hp_mode_hier_endfn_cb(hp_entry_t **entries)
{
    hp_entry_t      *top = (*entries);
    zval            *counts;
    char             stack_buf[4096];
    char            *symbol = stack_buf;
    size_t           symbol_len;
    long int        mu_end;
    long int        pmu_end;
    double          wt, cpu;

#if PHP_VERSION_ID >= 80000
    if (top->is_trace == 0) {
        /* Dummy entries pushed for ignored functions never incremented the
         * counter in hp_mode_common_beginfn(), so do not decrement here */
        return;
    }
#endif

    /* Get end tsc counter */
    wt = cycle_timer() - top->tsc_start;

    /* Build the "caller==>callee" symbol.  Most symbols fit into stack_buf;
     * long ones get an exactly sized heap buffer instead of being truncated
     * (truncation merged distinct long function names into one entry). */
    symbol_len = hp_get_function_stack_length(top, 2);
    if (symbol_len + 1 > sizeof(stack_buf)) {
        symbol = emalloc(symbol_len + 1);
    }
    hp_get_function_stack(top, 2, symbol, symbol_len + 1);

    /* Get the stat array */
    counts = zend_hash_str_find(Z_ARRVAL(XHPROF_G(stats_count)), symbol, symbol_len);

    if (counts == NULL) {
        zval count_val;
        array_init(&count_val);
        counts = zend_hash_str_update(Z_ARRVAL(XHPROF_G(stats_count)), symbol, symbol_len, &count_val);
    }

    /* Bump stats in the counts hashtable */
    hp_inc_count(counts, "ct", 1);
    hp_inc_count(counts, "wt", wt);

    if (XHPROF_G(xhprof_flags) & XHPROF_FLAGS_CPU) {
        cpu = cpu_timer() - top->cpu_start;

        /* Bump CPU stats in the counts hashtable */
        hp_inc_count(counts, "cpu", cpu);
    }

    if (XHPROF_G(xhprof_flags) & XHPROF_FLAGS_MEMORY) {
        /* Get Memory usage */
        mu_end  = zend_memory_usage(0);
        pmu_end = zend_memory_peak_usage(0);

        /* Bump Memory stats in the counts hashtable */
        hp_inc_count(counts, "mu",  mu_end - top->mu_start_hprof);
        hp_inc_count(counts, "pmu", pmu_end - top->pmu_start_hprof);
    }

    XHPROF_G(func_hash_counters[top->hash_code])--;

    if (symbol != stack_buf) {
        efree(symbol);
    }
}

/**
 * XHPROF_MODE_SAMPLED's end function callback
 *
 * @author veeve
 */
void hp_mode_sampled_endfn_cb(hp_entry_t **entries)
{
    /* See if its time to take a sample */
    hp_sample_check(entries);
}


/**
 * ***************************
 * PHP EXECUTE/COMPILE PROXIES
 * ***************************
 */

/**
 * XHProf enable replaced the zend_execute function with this
 * new execute function. We can do whatever profiling we need to
 * before and after calling the actual zend_execute().
 *
 * @author hzhao, kannan
 */

#if PHP_VERSION_ID >= 80000
static void tracer_observer_begin(zend_execute_data *execute_data) {
    if (!XHPROF_G(enabled)) {
        return;
    }

#if PHP_VERSION_ID >= 80200
    if (execute_data->func->type == ZEND_INTERNAL_FUNCTION) {
        return;
    }
#endif

    begin_profiling(NULL, execute_data);
}

static void tracer_observer_end(zend_execute_data *execute_data, zval *return_value) {
    if (!XHPROF_G(enabled)) {
        return;
    }

    if (XHPROF_G(entries) && XHPROF_G(entries)->prev_hprof) {
#if PHP_VERSION_ID >= 80200
        if (execute_data->func->type == ZEND_INTERNAL_FUNCTION) {
            return;
        }
#endif

        end_profiling();
    }
}

static zend_observer_fcall_handlers tracer_observer(zend_execute_data *execute_data) {
    zend_observer_fcall_handlers handlers = {NULL, NULL};

    if (!execute_data->func || !execute_data->func->common.function_name) {
        return handlers;
    }

    handlers.begin = tracer_observer_begin;
    handlers.end = tracer_observer_end;
    return handlers;
}
#else
ZEND_DLEXPORT void hp_execute_ex (zend_execute_data *execute_data)
{
    int is_profiling = 1;

    if (!XHPROF_G(enabled)) {
        _zend_execute_ex(execute_data);
        return;
    }

    //zend_execute_data *real_execute_data = execute_data->prev_execute_data;

    is_profiling = begin_profiling(NULL, execute_data);

    _zend_execute_ex(execute_data);

    if (is_profiling == 1 && XHPROF_G(entries)) {
        end_profiling();
    }
}
#endif

/**
 * Very similar to hp_execute. Proxy for zend_execute_internal().
 * Applies to zend builtin functions.
 *
 * @author hzhao, kannan
 */

ZEND_DLEXPORT void hp_execute_internal(zend_execute_data *execute_data, zval *return_value)
{
    int is_profiling = 1;

    if (!XHPROF_G(enabled) || (XHPROF_G(xhprof_flags) & XHPROF_FLAGS_NO_BUILTINS) > 0) {
        execute_internal(execute_data, return_value);
        return;
    }

    is_profiling = begin_profiling(NULL, execute_data);

    if (!_zend_execute_internal) {
        /* no old override to begin with. so invoke the builtin's implementation  */
        execute_internal(execute_data, return_value);
    } else {
        /* call the old override */
        _zend_execute_internal(execute_data, return_value);
    }

    if (is_profiling == 1 && XHPROF_G(entries)) {
        end_profiling();
    }
}

/**
 * Proxy for zend_compile_file(). Used to profile PHP compilation time.
 *
 * @author kannan, hzhao
 */
ZEND_DLEXPORT zend_op_array* hp_compile_file(zend_file_handle *file_handle, int type)
{
    int is_profiling = 1;

    if (!XHPROF_G(enabled)) {
        return _zend_compile_file(file_handle, type);
    }

    const char *filename;
    zend_string *function_name;
    zend_op_array *op_array;

#if PHP_VERSION_ID < 80100
    filename = hp_get_base_filename(file_handle->filename);
#else
    filename = hp_get_base_filename(ZSTR_VAL(file_handle->filename));
#endif

    function_name = strpprintf(0, "load::%s", filename);

    is_profiling = begin_profiling(function_name, NULL);
    op_array = _zend_compile_file(file_handle, type);

    if (is_profiling == 1 && XHPROF_G(entries)) {
        end_profiling();
    }

    zend_string_release(function_name);

    return op_array;
}

/**
 * Proxy for zend_compile_string(). Used to profile PHP eval compilation time.
 */
#if PHP_VERSION_ID < 80000
ZEND_DLEXPORT zend_op_array* hp_compile_string(zval *source_string, char *filename)
#elif PHP_VERSION_ID >= 80200
ZEND_DLEXPORT zend_op_array* hp_compile_string(zend_string *source_string, const char *filename, zend_compile_position position)
#else
ZEND_DLEXPORT zend_op_array* hp_compile_string(zend_string *source_string, const char *filename)
#endif
{
    int is_profiling = 1;

    if (!XHPROF_G(enabled)) {
#if PHP_VERSION_ID >= 80200
        return _zend_compile_string(source_string, filename, position);
#else
        return _zend_compile_string(source_string, filename);
#endif
    }

    zend_string *function_name;
    zend_op_array *op_array;

    function_name = strpprintf(0, "eval::%s", filename);

    is_profiling = begin_profiling(function_name, NULL);
#if PHP_VERSION_ID >= 80200
    op_array = _zend_compile_string(source_string, filename, position);
#else
    op_array = _zend_compile_string(source_string, filename);
#endif

    if (is_profiling == 1 && XHPROF_G(entries)) {
        end_profiling();
    }

    zend_string_release(function_name);

    return op_array;
}

/**
 * **************************
 * MAIN XHPROF CALLBACKS
 * **************************
 */

/**
 * This function gets called once when xhprof gets enabled.
 * It replaces all the functions like zend_execute, zend_execute_internal,
 * etc that needs to be instrumented with their corresponding proxies.
 */
static int hp_begin(zend_long level, zend_long xhprof_flags)
{
    /* xhprof.profiler=0: no instrumentation was installed, so there is
     * nothing to profile. Refusing here (rather than starting a profiler
     * that only ever sees "main()") is what makes the setting honest. */
    if (!XHPROF_G(profiler)) {
        return 0;
    }

    if (!XHPROF_G(enabled)) {
        XHPROF_G(enabled)      = 1;
        XHPROF_G(xhprof_flags) = (uint32)xhprof_flags;

        /* Initialize with the dummy mode first Having these dummy callbacks saves
         * us from checking if any of the callbacks are NULL everywhere. */
        XHPROF_G(mode_cb).init_cb     = hp_mode_dummy_init_cb;
        XHPROF_G(mode_cb).exit_cb     = hp_mode_dummy_exit_cb;
        XHPROF_G(mode_cb).begin_fn_cb = hp_mode_dummy_beginfn_cb;
        XHPROF_G(mode_cb).end_fn_cb   = hp_mode_dummy_endfn_cb;

        /* Register the appropriate callback functions Override just a subset of
        * all the callbacks is OK. */
        switch (level) {
            case XHPROF_MODE_HIERARCHICAL:
                XHPROF_G(mode_cb).begin_fn_cb = hp_mode_hier_beginfn_cb;
                XHPROF_G(mode_cb).end_fn_cb   = hp_mode_hier_endfn_cb;
                break;
            case XHPROF_MODE_SAMPLED:
                XHPROF_G(mode_cb).init_cb     = hp_mode_sampled_init_cb;
                XHPROF_G(mode_cb).begin_fn_cb = hp_mode_sampled_beginfn_cb;
                XHPROF_G(mode_cb).end_fn_cb   = hp_mode_sampled_endfn_cb;
                break;
        }

        /* one time initializations */
        hp_init_profiler_state(level);

        /* start profiling from fictitious main() */
        XHPROF_G(root) = zend_string_init(ROOT_SYMBOL, sizeof(ROOT_SYMBOL) - 1, 0);

        /* start profiling from fictitious main() */
        begin_profiling(XHPROF_G(root), NULL);
    }

    return 1;
}

/**
 * Called at request shutdown time. Cleans the profiler's global state.
 */
static void hp_end()
{
    /* Bail if not ever enabled */
    if (!XHPROF_G(ever_enabled)) {
        return;
    }

    /* Stop profiler if enabled */
    if (XHPROF_G(enabled)) {
        hp_stop();
    }

    /* Clean up state */
    hp_clean_profiler_state();
}

/**
 * Called from xhprof_disable(). Removes all the proxies setup by
 * hp_begin() and restores the original values.
 */
static void hp_stop()
{
    /* End any unfinished calls */
    while (XHPROF_G(entries)) {
        end_profiling();
    }

    /* Stop profiling */
    XHPROF_G(enabled) = 0;

    if (XHPROF_G(root)) {
        zend_string_release(XHPROF_G(root));
        XHPROF_G(root) = NULL;
    }
}


/**
 * *****************************
 * XHPROF ZVAL UTILITY FUNCTIONS
 * *****************************
 */

///* Free this memory at the end of profiling */
static inline void hp_array_del(zend_string **names)
{
    if (names != NULL) {
        int i = 0;
        for (; names[i] != NULL && i < XHPROF_MAX_IGNORED_FUNCTIONS; i++) {
            zend_string_release(names[i]);
            names[i] = NULL;
        }

        efree(names);
    }
}

int hp_pcre_match(zend_string *pattern, const char *str, size_t len)
{
    pcre_cache_entry *pce_regexp;

    if ((pce_regexp = pcre_get_compiled_regex_cache(pattern)) == NULL) {
        return 0;
    } else {
        zval matches, subparts;

        ZVAL_NULL(&subparts);

#if PHP_VERSION_ID < 70400
        php_pcre_match_impl(pce_regexp, (char*)str, len, &matches, &subparts /* subpats */,
                        0/* global */, 0/* ZEND_NUM_ARGS() >= 4 */, 0/*flags PREG_OFFSET_CAPTURE*/, 0/* start_offset */);
#else
        zend_string *tmp = zend_string_init(str, len, 0);
        php_pcre_match_impl(pce_regexp, tmp, &matches, &subparts /* subpats */,
#if PHP_VERSION_ID < 80400
                            0/* global */,
                            0/* ZEND_NUM_ARGS() >= 4 */,
#else
                            false/* global */,
#endif
                            0/*flags PREG_OFFSET_CAPTURE*/, 0/* start_offset */);
        zend_string_release(tmp);
#endif

        if (!zend_hash_num_elements(Z_ARRVAL(subparts))) {
            zval_ptr_dtor(&subparts);
            return 0;
        }

        zval_ptr_dtor(&subparts);

        return 1;
    }
}

zend_string *hp_pcre_replace(zend_string *pattern, zend_string *repl, zval *data, int limit)
{
    pcre_cache_entry *pce_regexp;
    zend_string *replace;

    if ((pce_regexp = pcre_get_compiled_regex_cache(pattern)) == NULL) {
        return NULL;
    }

#if PHP_VERSION_ID < 70200
    if (Z_TYPE_P(data) != IS_STRING) {
        convert_to_string(data);
    }

    replace = php_pcre_replace_impl(pce_regexp, NULL, ZSTR_VAL(repl), ZSTR_LEN(repl), data, 0, limit, 0);
#elif PHP_VERSION_ID >= 70200
    zend_string *tmp = zval_get_string(data);
    replace = php_pcre_replace_impl(pce_regexp, NULL, ZSTR_VAL(repl), ZSTR_LEN(repl), tmp, limit, 0);
    zend_string_release(tmp);
#endif

    return replace;
}

zend_string *hp_trace_callback_sql_query(zend_string *function_name, zend_execute_data *data)
{
    zend_string *trace_name;
    uint32_t arg_num = 1;
    zval *arg;

    if (strcmp(ZSTR_VAL(function_name), "mysqli_query") == 0) {
        arg_num = 2;
    }

    if (ZEND_CALL_NUM_ARGS(data) < arg_num) {
        trace_name = strpprintf(0, "%s", ZSTR_VAL(function_name));
        return trace_name;
    }

    arg = ZEND_CALL_ARG(data, arg_num);
    if (Z_TYPE_P(arg) != IS_STRING) {
        trace_name = strpprintf(0, "%s", ZSTR_VAL(function_name));
        return trace_name;
    }

    trace_name = strpprintf(0, "%s#%s", ZSTR_VAL(function_name), Z_STRVAL_P(arg));

    return trace_name;
}

zend_string *hp_trace_callback_pdo_statement_execute(zend_string *symbol, zend_execute_data *data)
{
    zend_string *result = NULL;
    zend_string *pattern = NULL;
    zval *object = (data->This.value.obj) ? &(data->This) : NULL;
    zval *query_string, *arg;

    if (object != NULL) {
#if PHP_VERSION_ID < 80000
        query_string = zend_read_property(NULL, object, "queryString", sizeof("queryString") - 1, 0, NULL);
#else
        query_string = zend_read_property(NULL, Z_OBJ_P(object), "queryString", sizeof("queryString") - 1, 0, NULL);
#endif

        if (query_string == NULL || Z_TYPE_P(query_string) != IS_STRING) {
            result = strpprintf(0, "%s", ZSTR_VAL(symbol));
            return result;
        }

#ifndef HAVE_PCRE
        result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), Z_STRVAL_P(query_string));
        return result;
#endif

        if (ZEND_CALL_NUM_ARGS(data) < 1) {
            result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), Z_STRVAL_P(query_string));
            return result;
        }

        arg = ZEND_CALL_ARG(data, 1);
        if (Z_TYPE_P(arg) != IS_ARRAY) {
            result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), Z_STRVAL_P(query_string));
            return result;
        }

        zend_string *repl = zval_get_string(query_string);

        if (strstr(ZSTR_VAL(repl), "?") != NULL) {
            pattern = zend_string_init("([\?])", sizeof("([\?])") - 1, 0);
        } else if (strstr(ZSTR_VAL(repl), ":") != NULL) {
            pattern = zend_string_init("(:([^\\s]+))", sizeof("(:([^\\s]+))") - 1, 0);
        }

        if (pattern) {
            if (hp_pcre_match(pattern, ZSTR_VAL(repl), ZSTR_LEN(repl))) {
                zval *val;
                zend_string *replace;

                ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(arg), val)
                {
                    replace = hp_pcre_replace(pattern, repl, val, 1);

                    if (replace == NULL) {
                        break;
                    }

                    zend_string_release(repl);
                    repl = replace;

                }ZEND_HASH_FOREACH_END();
            }

            zend_string_release(pattern);

            result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), ZSTR_VAL(repl));

        } else {
            result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), ZSTR_VAL(repl));
        }

        zend_string_release(repl);
    } else {
        result = zend_string_init(ZSTR_VAL(symbol), ZSTR_LEN(symbol), 0);
    }

    return result;
}

zend_string *hp_trace_callback_curl_exec(zend_string *symbol, zend_execute_data *data)
{
    zend_string *result;
    zval func, retval, *option;
    zval *arg;

    if (ZEND_CALL_NUM_ARGS(data) < 1) {
        result = strpprintf(0, "%s", ZSTR_VAL(symbol));
        return result;
    }

    arg = ZEND_CALL_ARG(data, 1);

#if PHP_VERSION_ID < 80000
    if (Z_TYPE_P(arg) != IS_RESOURCE) {
#else
    if (Z_TYPE_P(arg) != IS_OBJECT) {
#endif
        result = strpprintf(0, "%s", ZSTR_VAL(symbol));
        return result;
    }

    /* curl_getinfo() may be unavailable (e.g. listed in disable_functions);
     * skip the auxiliary call so we don't raise an error in profiled code */
    if (!zend_hash_str_exists(EG(function_table), "curl_getinfo", sizeof("curl_getinfo") - 1)) {
        result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), "unknown");
        return result;
    }

    zval params[1];
    ZVAL_COPY(&params[0], arg);
    ZVAL_STRING(&func, "curl_getinfo");
    ZVAL_UNDEF(&retval);

    zend_fcall_info fci = {
            sizeof(fci),
#if PHP_VERSION_ID < 70100
            EG(function_table),
#endif
            func,
#if PHP_VERSION_ID < 70100
            NULL,
#endif
            &retval,
            params,
            NULL,
#if PHP_VERSION_ID < 80000
            1,
#endif
            1
    };

    if (zend_call_function(&fci, NULL) == FAILURE) {
        result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), "unknown");
    } else {
        option = (Z_TYPE(retval) == IS_ARRAY)
                 ? zend_hash_str_find(Z_ARRVAL(retval), "url", sizeof("url") - 1) : NULL;

        if (option == NULL || Z_TYPE_P(option) != IS_STRING || Z_STRLEN_P(option) == 0) {
            result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), "unknown");
        } else {
            result = strpprintf(0, "%s#%s", ZSTR_VAL(symbol), Z_STRVAL_P(option));
        }
    }

    zval_ptr_dtor(&func);
    zval_ptr_dtor(&retval);
    zval_ptr_dtor(&params[0]);

    return result;
}

static inline void hp_free_trace_callbacks(zval *val) {
    efree(Z_PTR_P(val));
}

void hp_init_trace_callbacks()
{
    hp_trace_callback callback;

    if (!XHPROF_G(collect_additional_info)) {
        return;
    }

    if (XHPROF_G(trace_callbacks)) {
        return;
    }

    XHPROF_G(trace_callbacks) = NULL;
    ALLOC_HASHTABLE(XHPROF_G(trace_callbacks));

    if (!XHPROF_G(trace_callbacks)) {
        return;
    }

    zend_hash_init(XHPROF_G(trace_callbacks), 8, NULL, hp_free_trace_callbacks, 0);

    callback = hp_trace_callback_sql_query;
    register_trace_callback("PDO::exec", callback);
    register_trace_callback("PDO::query", callback);
    register_trace_callback("mysql_query", callback);
    register_trace_callback("mysqli_query", callback);
    register_trace_callback("mysqli::query", callback);

    callback = hp_trace_callback_pdo_statement_execute;
    register_trace_callback("PDOStatement::execute", callback);

    callback = hp_trace_callback_curl_exec;
    register_trace_callback("curl_exec", callback);
}
