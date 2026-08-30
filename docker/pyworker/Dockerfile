FROM docker:27-cli

RUN apk add --no-cache python3 py3-pymysql

COPY docker/pyworker/entrypoint.sh /usr/local/bin/ember-coreui-pyworker
RUN chmod 0755 /usr/local/bin/ember-coreui-pyworker

ENTRYPOINT ["/usr/local/bin/ember-coreui-pyworker"]
