FROM registry.shanemcc.net/mydnshost-public/mydnshost-api-docker-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

COPY . /dnsapi

RUN \
  chown -Rfv www-data: /dnsapi/ && \
  su www-data --shell=/bin/bash -c "cd /dnsapi; /usr/bin/composer install"
