# mydnshost-api

This repo holds the code for the api backend for mydnshost.

This is the code that does the main heavy-lifting and is exposed as a JSON API for https://github.com/mydnshost/mydnshost-frontend to access.

Domain/Record data is all stored in our own database and then pushed out to our DNS Server(s) via hooks.

The code can be run either with Docker or directly on a server, though for the most part production use is only tested as a docker container.

## Running

This is probably not useful on it's own, see https://github.com/mydnshost/mydnshost-infra

## Comments, Questions, Bugs, Feature Requests etc.

Bugs and Feature Requests should be raised on the [issue tracker on github](https://github.com/shanemcc/mydnshost-api/issues), and I'm happy to receive code pull requests via github.

I can be found idling on various different IRC Networks, but the best way to get in touch would be to message "Dataforce" on Quakenet (or chat in #Dataforce), or drop me a mail (email address is in my github profile)
