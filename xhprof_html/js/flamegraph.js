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
 * Renderer for the approximate flame graph built by flamegraph.php.
 *
 * Expects window.xhprof_flamegraph_data to hold a nested tree of
 * {n: name, w: width, s: self, c: [children]}. Frames are SVG rects:
 * hover shows the function name and width, clicking a frame zooms into
 * it, and the breadcrumb above the graph zooms back out. Root is drawn
 * at the bottom, like a classic flame graph.
 */
(function () {
  var data = window.xhprof_flamegraph_data;
  var container = document.getElementById('flamegraph');
  var crumb = document.getElementById('breadcrumb');

  if (!data || !container) {
    return;
  }

  var SVG_NS = 'http://www.w3.org/2000/svg';
  var ROW_H = 18;
  var FRAME_H = 16;
  var MIN_PX = 0.5;   // don't draw frames thinner than this

  // zoom path: path[path.length - 1] is the frame currently filling the
  // graph.
  var path = [data];

  function current() {
    return path[path.length - 1];
  }

  function el(name, attrs) {
    var e = document.createElementNS(SVG_NS, name);
    for (var k in attrs) {
      e.setAttribute(k, attrs[k]);
    }
    return e;
  }

  function commas(n) {
    var s = String(Math.round(n));
    var out = '';
    while (s.length > 3) {
      out = ',' + s.slice(-3) + out;
      s = s.slice(0, -3);
    }
    return s + out;
  }

  function color(name) {
    if (name === '(others)') {
      return 'hsl(0, 0%, 78%)';
    }
    var h = 0;
    for (var i = 0; i < name.length; i++) {
      h = (h * 31 + name.charCodeAt(i)) % 360;
    }
    return 'hsl(' + h + ', 65%, 70%)';
  }

  function maxDepth(node) {
    var d = 0;
    for (var i = 0; i < node.c.length; i++) {
      var cd = 1 + maxDepth(node.c[i]);
      if (cd > d) {
        d = cd;
      }
    }
    return d;
  }

  function drawNode(svg, node, x, y, scale, rootW) {
    // a width can never be negative: clamp defensively as well
    var w = Math.max(0, node.w);
    var px = w * scale;
    if (px < MIN_PX) {
      return;
    }

    var rect = el('rect', {
      x: x, y: y, width: px, height: FRAME_H,
      fill: color(node.n), stroke: '#fff'
    });
    var pct = rootW > 0 ? (100 * w / rootW) : 0;
    var title = el('title');
    title.appendChild(document.createTextNode(
      node.n + '\n' + commas(w) + ' (' + pct.toFixed(1) + '% of shown root)'
      + '\nself: ' + commas(node.s)));
    rect.appendChild(title);
    rect.style.cursor = 'pointer';
    rect.onclick = function () {
      path.push(node);
      render();
    };
    svg.appendChild(rect);

    if (px > 30) {
      var text = el('text', {x: x + 3, y: y + 12, fill: '#333'});
      text.appendChild(document.createTextNode(
        px > 200 ? node.n : node.n.slice(0, Math.floor(px / 6))));
      svg.appendChild(text);
    }

    var childX = x;
    for (var i = 0; i < node.c.length; i++) {
      drawNode(svg, node.c[i], childX, y - ROW_H, scale, rootW);
      childX += Math.max(0, node.c[i].w) * scale;
    }
  }

  function renderBreadcrumb() {
    crumb.innerHTML = '';
    for (var i = 0; i < path.length; i++) {
      (function (idx) {
        var span = document.createElement('span');
        span.appendChild(document.createTextNode(path[idx].n));
        span.title = 'zoom to ' + path[idx].n;
        span.onclick = function () {
          path = path.slice(0, idx + 1);
          render();
        };
        crumb.appendChild(span);
      })(i);
      if (i < path.length - 1) {
        crumb.appendChild(document.createTextNode(' > '));
      }
    }
    if (path.length > 1) {
      var reset = document.createElement('span');
      reset.appendChild(document.createTextNode('  [reset]'));
      reset.onclick = function () {
        path = [data];
        render();
      };
      crumb.appendChild(reset);
    }
  }

  function render() {
    var rootNode = current();
    var total = Math.max(0, rootNode.w);
    var width = Math.max(container.clientWidth || 0, 400);
    var scale = total > 0 ? (width / total) : 0;
    var depth = maxDepth(rootNode);

    var svg = el('svg', {width: width, height: (depth + 1) * ROW_H});
    // root at the bottom: depth d is drawn d rows above the root row
    drawNode(svg, rootNode, 0, depth * ROW_H, scale, total);

    container.innerHTML = '';
    container.appendChild(svg);
    renderBreadcrumb();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
