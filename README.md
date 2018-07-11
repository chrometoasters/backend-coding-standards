# Back-end Coding Standard

Back-end coding standards used at [Chrometoaster](https://www.chrometoaster.com) are based on [PSR-2](http://www.php-fig.org/psr/psr-2/).

This project bundles tools along with predefined rulesets for automated checks.
Provided tools:

* [PHP-Parallel-Lint](https://github.com/JakubOnderka/PHP-Parallel-Lint)
* [EasyCodingStandard](https://github.com/Symplify/EasyCodingStandard) that combines [PHP-CS-Fixer](https://github.com/FriendsOfPHP/PHP-CS-Fixer) and [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)


## Installation & usage

1. Install this package:

    ```bash
    $ composer require --dev chrometoaster/backend-coding-standards:~1.0
    ```
    
2. Include a configuration file in your `easy-coding-standard.yml`:

    ```yml
    imports:
        - { resource: '%vendor_dir%/chrometoaster/backend-coding-standards/config/chrometoaster.yml' }
    ```

3. Check your files

   ```bash
   $ vendor/bin/parallel-lint /path/to/source/code
   $ vendor/bin/ecs check path/to/source/code
   ```

4. Auto-fix non-compliant files where possible 

   ```bash
   $ vendor/bin/ecs check path/to/source/code --fix
   ```

See the official documentation of the tools used for further information, e.g. how to provide a custom config.


## Licence

BSD-3-Clause
