---
title: "Proof | SESAME docs"
description: "Proof runs against real Wayne State applications on a private site, behind a Wayne State employee sign-in."
url: https://sesame.apps.wayne.edu/docs/reference/proof.html
llms: https://sesame.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Proof[#](https://sesame.apps.wayne.edu/docs/reference/proof.html#proof)

The name does double duty: this is where SESAME proves itself. There are two kinds of proof, the private proof site that exercises the library against real Wayne State applications, and the test suite anyone can run locally.

## The proof site[#](https://sesame.apps.wayne.edu/docs/reference/proof.html#the-proof-site)

Proof runs against real Wayne State applications on a private site, [proof.apps.wayne.edu/sesame](https://proof.apps.wayne.edu/sesame/), behind a Wayne State employee sign-in. It is the place to check the library end to end, against the real services, before anything ships.

## Tests[#](https://sesame.apps.wayne.edu/docs/reference/proof.html#tests)

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://nougat.snu.gs/enterprise-apps/sesame/MAINTAINING.md).

No PHP on the machine? Fine. Docker runs the suite:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

Static analysis is PHPStan at level 8 from Pinstripe's strict preset (`vendor/waynestate/pinstripe/phpstan/strict.neon`, included through the `waynestate/pinstripe` dev dependency): strict rules stay on, and a call to a deprecated method fails the build. `composer ci` runs the whole gate, validate through tests, Pint and PHPStan:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer ci
```

## Related[#](https://sesame.apps.wayne.edu/docs/reference/proof.html#related)

- [Drivers](https://sesame.apps.wayne.edu/docs/reference/drivers.html)
- [Configuration](https://sesame.apps.wayne.edu/docs/reference/configuration.html)
- [API](https://sesame.apps.wayne.edu/docs/reference/api.html)
