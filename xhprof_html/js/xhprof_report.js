/*  Copyright (c) 2009 Facebook
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
 */

/**
 * Helper javascript functions for XHProf report tooltips.
 *
 * @author Kannan Muthukkaruppan
 */

// Take a string which is actually a number in comma separated format
// and return a string representing the absolute value of the number.
function stringAbs(x) {
  return x.replace("-", "");
}

// Takes a number in comma-separated string format, and
// returns a boolean to indicate if the number is negative
// or not.
function isNegative(x) {

  return (x.indexOf("-") == 0);

}

function addCommas(nStr)
{
  nStr += '';
  x = nStr.split('.');
  x1 = x[0];
  x2 = x.length > 1 ? '.' + x[1] : '';
  var rgx = /(\d+)(\d{3})/;
  while (rgx.test(x1)) {
    x1 = x1.replace(rgx, '$1' + ',' + '$2');
  }
  return x1 + x2;
}

// Mouseover tips for parent rows in parent/child report..
function ParentRowToolTip(cell, metric)
{
  var metric_val;
  var parent_metric_val;
  var parent_metric_pct_val;
  var col_index;
  var diff_text;

  row = cell.parentNode;
  tds = row.getElementsByTagName("td");

  parent_func    = tds[0].innerHTML;  // name

  if (diff_mode) {
    diff_text = " diff ";
  } else {
    diff_text = "";
  }

  s = '<center>';

  if (metric == "ct") {
    parent_ct      = tds[1].innerHTML;  // calls
    parent_ct_pct  = tds[2].innerHTML;

    func_ct = addCommas(func_ct);

    if (diff_mode) {
      s += 'There are ' + stringAbs(parent_ct) +
        (isNegative(parent_ct) ? ' fewer ' : ' more ') +
        ' calls to ' + func_name + ' from ' + parent_func + '<br>';

      text = " of diff in calls ";
    }  else {
      text = " of calls ";
    }

    s += parent_ct_pct + text + '(' + parent_ct + '/' + func_ct + ') to '
      + func_name + ' are from ' + parent_func + '<br>';
  } else {

    // help for other metrics such as wall time, user cpu time, memory usage
    col_index = metrics_col[metric];
    parent_metric_val     = tds[col_index].innerHTML;
    parent_metric_pct_val = tds[col_index+1].innerHTML;

    metric_val = addCommas(func_metrics[metric]);

    s += parent_metric_pct_val + '(' + parent_metric_val + '/' + metric_val
      + ') of ' + metrics_desc[metric] +
      (diff_mode ? ((isNegative(parent_metric_val) ?
                    " decrease" : " increase")) : "") +
      ' in ' + func_name + ' is due to calls from ' + parent_func + '<br>';
  }

  s += '</center>';

  return s;
}

// Mouseover tips for child rows in parent/child report..
function ChildRowToolTip(cell, metric)
{
  var metric_val;
  var child_metric_val;
  var child_metric_pct_val;
  var col_index;
  var diff_text;

  row = cell.parentNode;
  tds = row.getElementsByTagName("td");

  child_func   = tds[0].innerHTML;  // name

  if (diff_mode) {
    diff_text = " diff ";
  } else {
    diff_text = "";
  }

  s = '<center>';

  if (metric == "ct") {

    child_ct     = tds[1].innerHTML;  // calls
    child_ct_pct = tds[2].innerHTML;

    s += func_name + ' called ' + child_func + ' ' + stringAbs(child_ct) +
      (diff_mode ? (isNegative(child_ct) ? " fewer" : " more") : "" )
        + ' times.<br>';
    s += 'This accounts for ' + child_ct_pct + ' (' + child_ct
        + '/' + total_child_ct
        + ') of function calls made by '  + func_name + '.';

  } else {

    // help for other metrics such as wall time, user cpu time, memory usage
    col_index = metrics_col[metric];
    child_metric_val     = tds[col_index].innerHTML;
    child_metric_pct_val = tds[col_index+1].innerHTML;

    metric_val = addCommas(func_metrics[metric]);

    if (child_func.indexOf("Exclusive Metrics") != -1) {
      s += 'The exclusive ' + metrics_desc[metric] + diff_text
        + ' for ' + func_name
        + ' is ' + child_metric_val + " <br>";

      s += "which is " + child_metric_pct_val + " of the inclusive "
        + metrics_desc[metric]
        + diff_text + " for " + func_name + " (" + metric_val + ").";

    } else {

      s += child_func + ' when called from ' + func_name
        + ' takes ' + stringAbs(child_metric_val)
        + (diff_mode ? (isNegative(child_metric_val) ? " less" : " more") : "")
        + " of " + metrics_desc[metric] + " <br>";

      s += "which is " + child_metric_pct_val + " of the inclusive "
        + metrics_desc[metric]
        + diff_text + " for " + func_name + " (" + metric_val + ").";
    }
  }

  s += '</center>';

  return s;
}

// Tooltip for the metric cells of the parent/child report. Replaces the
// jQuery Tooltip plugin: one absolutely positioned #tooltip div, shown after
// a short delay, flipped back over the cursor when it would leave the
// viewport.
function initMetricTooltip() {
  var tip = document.createElement('div');
  tip.id = 'tooltip';
  tip.style.display = 'none';
  var body = document.createElement('div');
  body.className = 'body';
  tip.appendChild(body);
  document.body.appendChild(tip);

  var current = null;
  var timer = null;
  var x = 0;
  var y = 0;

  function show() {
    timer = null;
    var type = current.getAttribute('type');
    var metric = current.getAttribute('metric');
    if (type == 'Parent') {
      body.innerHTML = ParentRowToolTip(current, metric);
    } else if (type == 'Child') {
      body.innerHTML = ChildRowToolTip(current, metric);
    } else {
      return;
    }
    // lay out before measuring, then flip when the tip overflows the viewport
    tip.style.visibility = 'hidden';
    tip.style.display = 'block';
    tip.style.left = (x + 15) + 'px';
    tip.style.top = (y + 15) + 'px';
    var w = tip.offsetWidth;
    var h = tip.offsetHeight;
    if (x + 15 + w > window.scrollX + document.documentElement.clientWidth) {
      tip.style.left = (x - w - 20) + 'px';
    }
    if (y + 15 + h > window.scrollY + document.documentElement.clientHeight) {
      tip.style.top = (y - h - 20) + 'px';
    }
    tip.style.visibility = '';
  }

  function hide() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    current = null;
    tip.style.display = 'none';
  }

  function cellOf(event) {
    var el = event.target;
    return (el && el.closest) ? el.closest('td[metric]') : null;
  }

  document.addEventListener('mouseover', function(event) {
    var cell = cellOf(event);
    if (!cell || cell == current) {
      return;
    }
    current = cell;
    x = event.pageX;
    y = event.pageY;
    if (timer) {
      clearTimeout(timer);
    }
    timer = setTimeout(show, 200);
  });

  document.addEventListener('mouseout', function(event) {
    var cell = cellOf(event);
    if (!cell || (event.relatedTarget && cell.contains(event.relatedTarget))) {
      return;
    }
    hide();
  });

  // follow the mouse until the tip is shown, like the old plugin did
  document.addEventListener('mousemove', function(event) {
    if (current && timer) {
      x = event.pageX;
      y = event.pageY;
    }
  });

  document.addEventListener('click', function(event) {
    if (cellOf(event)) {
      hide();
    }
  });
}

// Typeahead for the "jump to function" box. Replaces the jQuery Autocomplete
// plugin. Every keystroke (debounced) asks the server again, because
// typeahead.php does the matching itself -- it puts prefix matches before
// mid-name matches, and re-filtering that list on the client (the plugin's
// matchSubset default) silently drops the mid-name hits as the query gets
// longer.
function initFunctionTypeahead() {
  var input = document.querySelector('input.function_typeahead');
  if (!input) {
    return;
  }

  var box = document.createElement('div');
  box.className = 'ac_results';
  box.style.display = 'none';
  var ul = document.createElement('ul');
  box.appendChild(ul);
  document.body.appendChild(box);

  var items = [];
  var active = -1;
  var timer = null;
  var seq = 0;

  function hide() {
    box.style.display = 'none';
    active = -1;
    if (ul.children.length) {
      mark();
    }
  }

  function mark() {
    var lis = ul.children;
    for (var i = 0; i < lis.length; i++) {
      lis[i].className = (i == active) ? 'ac_over' : (i % 2 ? 'ac_odd' : '');
    }
    // keep the active item in view, scrolling the list (not the page)
    if (active >= 0 && ul.clientHeight) {
      var li = lis[active];
      if (li.offsetTop < ul.scrollTop) {
        ul.scrollTop = li.offsetTop;
      } else if (li.offsetTop + li.offsetHeight > ul.scrollTop + ul.clientHeight) {
        ul.scrollTop = li.offsetTop + li.offsetHeight - ul.clientHeight;
      }
    }
  }

  function render(li, name, term) {
    var at = name.toLowerCase().indexOf(term);
    if (!term || at < 0) {
      li.appendChild(document.createTextNode(name));
      return;
    }
    li.appendChild(document.createTextNode(name.slice(0, at)));
    var strong = document.createElement('strong');
    strong.textContent = name.slice(at, at + term.length);
    li.appendChild(strong);
    li.appendChild(document.createTextNode(name.slice(at + term.length)));
  }

  function fill(names, term) {
    ul.textContent = '';
    items = names;
    for (var i = 0; i < names.length; i++) {
      var li = document.createElement('li');
      if (i % 2) {
        li.className = 'ac_odd';
      }
      render(li, names[i], term);
      ul.appendChild(li);
    }
    var rect = input.getBoundingClientRect();
    box.style.left = (rect.left + window.scrollX) + 'px';
    box.style.top = (rect.bottom + window.scrollY) + 'px';
    box.style.width = rect.width + 'px';
    box.style.display = names.length ? 'block' : 'none';
    active = names.length ? 0 : -1;  // the first match is pre-selected
    mark();
  }

  function move(step) {
    if (active < 0) {
      return;
    }
    active = (active + step + items.length) % items.length;
    mark();
  }

  function suggest() {
    var term = input.value.trim();
    if (!term) {
      hide();
      return;
    }
    var mine = ++seq;
    var params = new URLSearchParams(location.search);
    params.set('q', term.toLowerCase());
    fetch('typeahead.php?' + params.toString())
      .then(function(response) { return response.text(); })
      .then(function(text) {
        if (mine != seq) {
          return;  // a newer request is already in flight
        }
        var names = [];
        var rows = text.split('\n');
        for (var i = 0; i < rows.length; i++) {
          var row = rows[i].trim();
          if (row) {
            names.push(row);
          }
        }
        fill(names, term.toLowerCase());
      });
  }

  function select() {
    if (active < 0 || !items.length) {
      return false;
    }
    var value = items[active];  // read before hide() resets `active`
    input.value = value;
    hide();
    // carry the current query params over, as the old widget did
    var params = new URLSearchParams(location.search);
    params.set('symbol', value);
    location.search = '?' + params.toString();
    return true;
  }

  input.addEventListener('input', function() {
    if (timer) {
      clearTimeout(timer);
    }
    timer = setTimeout(suggest, 250);
  });

  input.addEventListener('keydown', function(event) {
    if (event.key == 'ArrowDown' || event.key == 'ArrowUp') {
      event.preventDefault();
      if (box.style.display == 'none') {
        suggest();
      } else {
        move(event.key == 'ArrowDown' ? 1 : -1);
      }
    } else if (event.key == 'Enter' || event.key == 'Tab') {
      if (box.style.display != 'none' && select()) {
        event.preventDefault();
      }
    } else if (event.key == 'Escape') {
      hide();
    }
  });

  input.addEventListener('blur', hide);

  // keep the input focused when an item is clicked, else blur() hides the
  // list before the click lands
  box.addEventListener('mousedown', function(event) {
    event.preventDefault();
  });

  box.addEventListener('mouseover', function(event) {
    var li = event.target;
    while (li && li.tagName != 'LI') {
      li = li.parentNode;
    }
    if (li && li.parentNode == ul) {
      active = Array.prototype.indexOf.call(ul.children, li);
      mark();
    }
  });

  box.addEventListener('click', function(event) {
    var li = event.target;
    while (li && li.tagName != 'LI') {
      li = li.parentNode;
    }
    if (li && li.parentNode == ul) {
      active = Array.prototype.indexOf.call(ul.children, li);
      select();
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  initMetricTooltip();
  initFunctionTypeahead();
});
