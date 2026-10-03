PHP_ARG_ENABLE(xhprof, whether to enable xhprof support,
[ --enable-xhprof      Enable xhprof support])

if test "$PHP_XHPROF" != "no"; then

  if test "$PHP_THREAD_SAFETY" = "yes"; then
    dnl ZTS builds use the static TSRMLS cache (matches XHPROF_G() using
    dnl ZEND_TSRMG in php_xhprof.h and config.w32 defining the same macro)
    CFLAGS="$CFLAGS -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1"
  fi

  AC_MSG_CHECKING([for PCRE includes])

  if test -f $phpincludedir/ext/pcre/php_pcre.h; then
    AC_DEFINE([HAVE_PCRE], 1, [have pcre headers])
    AC_MSG_RESULT([yes])
  else
    AC_MSG_RESULT([no])
  fi

  PHP_NEW_EXTENSION(xhprof, xhprof.c, $ext_shared)
fi
