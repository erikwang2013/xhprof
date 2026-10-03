<?php
//
//  Copyright (c) 2009 Facebook
//
//  Licensed under the Apache License, Version 2.0 (the "License");
//  you may not use this file except in compliance with the License.
//  You may obtain a copy of the License at
//
//      http://www.apache.org/licenses/LICENSE-2.0
//
//  Unless required by applicable law or agreed to in writing, software
//  distributed under the License is distributed on an "AS IS" BASIS,
//  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//  See the License for the specific language governing permissions and
//  limitations under the License.
//

//
// This file defines the interface iXHProfRuns and also provides a default
// implementation of the interface (class XHProfRuns).
//

/**
 * iXHProfRuns interface for getting/saving a XHProf run.
 *
 * Clients can either use the default implementation,
 * namely XHProfRuns_Default, of this interface or define
 * their own implementation.
 *
 * @author Kannan
 */
interface iXHProfRuns {

  /**
   * Returns XHProf data given a run id ($run) of a given
   * type ($type).
   *
   * Also, a brief description of the run is returned via the
   * $run_desc out parameter.
   */
  public function get_run($run_id, $type, &$run_desc);

  /**
   * Save XHProf data for a profiler run of specified type
   * ($type).
   *
   * The caller may optionally pass in run_id (which they
   * promise to be unique). If a run_id is not passed in,
   * the implementation of this method must generated a
   * unique run id for this saved XHProf run.
   *
   * Returns the run id for the saved XHProf run.
   *
   */
  public function save_run($xhprof_data, $type, $run_id = null);
}


/**
 * XHProfRuns_Default is the default implementation of the
 * iXHProfRuns interface for saving/fetching XHProf runs.
 *
 * It stores/retrieves runs to/from a filesystem directory
 * specified by the "xhprof.output_dir" ini parameter.
 *
 * @author Kannan
 */
class XHProfRuns_Default implements iXHProfRuns {

  private $dir = '';
  private $suffix = 'xhprof';

  private function gen_run_id($type) {
    return uniqid();
  }

  private function file_name($run_id, $type) {

    // Both parameters become part of the run's file name: restrict them
    // to a safe character set and reject ".." so they can not traverse
    // out of the run directory or refer to another path.
    foreach (array($run_id, $type) as $part) {
      if (!is_string($part) || !preg_match('/^[A-Za-z0-9_.-]+$/', $part)
          || strpos($part, '..') !== false) {
        xhprof_error("Invalid run id or type");
        return null;
      }
    }

    $file = "$run_id.$type." . $this->suffix;

    if (!empty($this->dir)) {
      $file = $this->dir . "/" . $file;

      // Defense in depth: make sure the resulting path really stays inside
      // the configured run directory.
      $real_dir = realpath($this->dir);
      if ($real_dir !== false) {
        $real_file = realpath($file);
        if ($real_file === false) {
          // the file does not exist yet (save_run): resolve it manually
          $real_file = $real_dir . DIRECTORY_SEPARATOR . basename($file);
        }
        $prefix = rtrim($real_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($real_file, $prefix, strlen($prefix)) !== 0) {
          xhprof_error("Invalid run id or type (path escapes run directory)");
          return null;
        }
      }
    }
    return $file;
  }

  public function __construct($dir = null) {

    // if user hasn't passed a directory location,
    // we use the xhprof.output_dir ini setting
    // if specified, else we default to the directory
    // in which the error_log file resides.

    if (empty($dir) && !($dir = getenv('XHPROF_OUTPUT_DIR'))) {
      $dir = ini_get("xhprof.output_dir");
      if (empty($dir)) {

        $dir = sys_get_temp_dir();

        xhprof_error("Warning: Must specify directory location for XHProf runs. ".
                     "Trying {$dir} as default. You can either pass the " .
                     "directory location as an argument to the constructor ".
                     "for XHProfRuns_Default() or set xhprof.output_dir ".
                     "ini param, or set XHPROF_OUTPUT_DIR environment variable.");
      }
    }
    $this->dir = $dir;
  }

  public function get_run($run_id, $type, &$run_desc) {
    // memoize runs already read during this request (e.g. the typeahead
    // endpoint can ask for the same run more than once).
    static $cached_runs = array();

    $file_name = $this->file_name($run_id, $type);
    if ($file_name === null) {
      $run_desc = "Invalid Run Id = " . (is_scalar($run_id) ? $run_id : '');
      return null;
    }

    if (isset($cached_runs[$file_name])) {
      $run_desc = $cached_runs[$file_name]['desc'];
      return $cached_runs[$file_name]['data'];
    }

    if (!file_exists($file_name)) {
      xhprof_error("Could not find file $file_name");
      $run_desc = "Invalid Run Id = $run_id";
      return null;
    }

    $contents = file_get_contents($file_name);
    if ($contents === false) {
      xhprof_error("Could not read file $file_name");
      $run_desc = "Invalid Run Id = $run_id";
      return null;
    }

    // Run files hold plain data: never instantiate objects while
    // unserializing them. Malformed files are reported through the
    // "Invalid Run Id" path below; silence the unserialize() warning so
    // it cannot leak into the response body when display_errors is on.
    $raw_data = @unserialize($contents, array('allowed_classes' => false));
    if (!is_array($raw_data)) {
      xhprof_error("Could not unserialize file $file_name");
      $run_desc = "Invalid Run Id = $run_id";
      return null;
    }

    // sampling profiler runs arrive as "timestamp => stack" entries; fold
    // them into the parent/child shape the reports expect.
    $raw_data = xhprof_expand_sampled_run($raw_data);

    // metric values are used in arithmetic all over the report code:
    // degrade anything that is not a number instead of blowing up later.
    $raw_data = xhprof_sanitize_run_data($raw_data);

    $run_desc = "XHProf Run (Namespace=$type)";
    $cached_runs[$file_name] = array('desc' => $run_desc, 'data' => $raw_data);
    return $raw_data;
  }

  /**
   * Return a run exactly as it was saved: no sample folding, no
   * sanitizing. get_run() already converts both, which makes it impossible
   * to tell a sampled run from an instrumented one afterwards; tools that
   * need the original shape can read it here.
   *
   * Returns the raw array, or null when the run cannot be read.
   */
  public function read_run_raw($run_id, $type = "xhprof") {
    $file_name = $this->file_name($run_id, $type);
    if ($file_name === null || !file_exists($file_name)) {
      return null;
    }

    $contents = file_get_contents($file_name);
    if ($contents === false) {
      return null;
    }

    $raw_data = @unserialize($contents, array('allowed_classes' => false));
    return is_array($raw_data) ? $raw_data : null;
  }

  public function save_run($xhprof_data, $type, $run_id = null) {

    if ($run_id === null) {
      $run_id = $this->gen_run_id($type);
    }

    $file_name = $this->file_name($run_id, $type);
    if ($file_name === null) {
      return null;
    }

    // Use PHP serialize function to store the XHProf's
    // raw profiler data.
    $xhprof_data = serialize($xhprof_data);

    $file = fopen($file_name, 'w');

    if ($file) {
      fwrite($file, $xhprof_data);
      fclose($file);
    } else {
      xhprof_error("Could not open $file_name\n");
    }

    // echo "Saved run in {$file_name}.\nRun id = {$run_id}.\n";
    return $run_id;
  }

  function list_runs() {
    if (is_dir($this->dir)) {
        $script_name = isset($_SERVER['SCRIPT_NAME']) ?
          $_SERVER['SCRIPT_NAME'] : 'index.php';
        $script_url = htmlentities($script_name);

        echo "<hr/>Existing runs:\n";
        // pick two runs and jump straight to the diff report for them
        echo '<div style="margin: 4px 0px;">'
            . '<button type="button" class="xhprof_compare_button" '
            . 'onclick="xhprofCompareSelectedRuns()">Compare selected</button> '
            . '<button type="button" class="xhprof_aggregate_button" '
            . 'onclick="xhprofAggregateSelectedRuns()">Aggregate selected</button> '
            . '<small>check exactly two runs; the one higher in the list '
            . '(the newer run) becomes run1</small></div>' . "\n";
        echo "<ul>\n";
        $files = glob("{$this->dir}/*.{$this->suffix}");
		usort($files, function($a, $b) {return filemtime($b) - filemtime($a);});
        foreach ($files as $file) {
            // file names are "<run id>.<type>.<suffix>". Run ids may contain
            // dots themselves ("my.run.1"), so strip the fixed suffix and
            // split the type off at the last remaining dot.
            $base = basename($file);
            $base = preg_replace('/\.' . preg_quote($this->suffix, '/') . '$/',
                                 '', $base);
            $dot = strrpos($base, '.');
            if ($dot === false) {
                // no type part: like the old explode() would, fall back to
                // the suffix as the source.
                $run = $base;
                $source = $this->suffix;
            } else {
                $run = substr($base, 0, $dot);
                $source = substr($base, $dot + 1);
            }
            echo '<li><input type="checkbox" class="xhprof_run_select" value="'
                . htmlentities($run) . '" data-source="'
                . htmlentities($source) . '"> <a href="' . $script_url
                . '?run=' . htmlentities($run) . '&source='
                . htmlentities($source) . '">'
                . htmlentities(basename($file)) . "</a><small> "
                . date("Y-m-d H:i:s", filemtime($file)) . "</small></li>\n";
        }
        echo "</ul>\n";
        echo "<script type=\"text/javascript\">\n"
            . "function xhprofCompareSelectedRuns() {\n"
            . "  var boxes = document.getElementsByClassName ? "
            . "document.getElementsByClassName('xhprof_run_select') : [];\n"
            . "  var picked = [];\n"
            . "  for (var i = 0; i < boxes.length; i++) {\n"
            . "    if (boxes[i].checked) { picked.push(boxes[i]); }\n"
            . "  }\n"
            . "  if (picked.length != 2) {\n"
            . "    alert('Check exactly two runs to compare them.');\n"
            . "    return;\n"
            . "  }\n"
            . "  location.href = " . json_encode($script_name)
            . " + '?run1=' + encodeURIComponent(picked[0].value)\n"
            . "    + '&run2=' + encodeURIComponent(picked[1].value)\n"
            . "    + '&source=' + encodeURIComponent("
            . "picked[0].getAttribute('data-source'));\n"
            . "}\n"
            . "function xhprofAggregateSelectedRuns() {\n"
            . "  var boxes = document.getElementsByClassName ? "
            . "document.getElementsByClassName('xhprof_run_select') : [];\n"
            . "  var picked = [];\n"
            . "  for (var i = 0; i < boxes.length; i++) {\n"
            . "    if (boxes[i].checked) { picked.push(boxes[i]); }\n"
            . "  }\n"
            . "  if (picked.length < 2) {\n"
            . "    alert('Check two or more runs to aggregate them.');\n"
            . "    return;\n"
            . "  }\n"
            . "  var runs = [];\n"
            . "  for (var i = 0; i < picked.length; i++) {\n"
            . "    runs.push(encodeURIComponent(picked[i].value));\n"
            . "  }\n"
            . "  location.href = " . json_encode($script_name)
            . " + '?run=' + runs.join(',')\n"
            . "    + '&source=' + encodeURIComponent("
            . "picked[0].getAttribute('data-source'));\n"
            . "}\n"
            . "</script>\n";
    }
  }
}
