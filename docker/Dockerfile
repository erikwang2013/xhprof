# One-command xhprof demo. Builds the extension from this checkout and serves
# the HTML UI with the PHP built-in server. Demo only -- not a production image.
#
#   docker compose up
#   open http://localhost:8080
#
# Port: the UI is served on 8080 inside the container; docker-compose.yml maps
# it to the host's 8080. Runs are written to /tmp/xhprof (the output_dir set
# below), which lives in the container -- it is seeded by compose on startup
# and discarded with the container.
FROM php:8.5-cli

# $PHPIZE_DEPS brings autoconf/gcc/make for phpize builds; graphviz provides
# `dot`, which xhprof_html/callgraph.php needs for callgraph images.
RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS graphviz \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /xhprof
COPY . .

RUN cd extension \
    && phpize \
    && ./configure \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable xhprof

# Where XHProfRuns_Default stores runs. Seeded by docker-compose.yml.
RUN mkdir -p /tmp/xhprof \
    && echo 'xhprof.output_dir=/tmp/xhprof' > /usr/local/etc/php/conf.d/xhprof.ini

EXPOSE 8080
# Serve the UI document root (xhprof_html/) on 0.0.0.0:8080. Compose overrides
# this command to seed one example run first.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "xhprof_html"]
