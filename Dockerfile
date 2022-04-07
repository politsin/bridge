FROM synst/s3-dockup:latest
MAINTAINER politsin <politsin@gmail.com>

#COPY script & config:::
RUN mkdir -p /opt/console
COPY console /opt/console

RUN rm /opt/console/composer.lock
RUN cd /opt/console && composer install

#Fix ownership
RUN chmod 755 /opt/console/bridge.php

ENTRYPOINT ["/usr/bin/php", "/opt/console/bridge.php", "start"]
