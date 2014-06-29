Accelerando - Speed up Satis repositories
=========================================

Takes an existing [Satis](https://github.com/composer/satis) repository and splits up the big packages.json into single packages
with proper provider includes and by that should speed up your composer install/update times.

Useful for when your satis repositories are getting bigger and you still can't/don't want to to use packagist
or you are mirroring a large amount of packages with [medusa](https://github.com/khepin/medusa).

The original packages.json is backed up and can simply be resored by renaming.

Usage
-----

- Fetch dependencies via [Composer](https://getcomposer.org/download/)
- Rebuild an existing satis repository: `php bin/accelerando build <satis.json> <build-dir>`

Requirements
------------

PHP 5.3+

License
-------

Accelerando is licensed under the MIT License - see the LICENSE file for details
