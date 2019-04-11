# Nexus Pusher

This package provider a phar file that allow to push the current package into a Nexus 
Composer repository hosted with [nexus-repository-composer](https://github.com/sonatype-nexus-community/nexus-repository-composer).

## Installation

1. Download nexus-push.phar.gz from dist directory.
2. unzip to nexus-push.phar

## Usage
Many of the options are optional since they can be added directly to the `composer.json` file.
```bash
 # At the root of your directory
 $ php nexus-push.phar nexus-push [--name=<package name>] \
   [--url=<URL to the composer nexus repository>] \
   [--username=USERNAME] \
   [--password=PASSWORD] \
   [--ignore-dirs=test]\
   [--ignore-dirs=foo]
   <version>
   
 # Example
 $ php nexus-push.phar --username=admin --password=admin123 --url=http://localhost:8081/repository/composer --ignore-dirs=test --ignore-dirs=foo 0.0.1
 ```

## Configuration
It's possible to add some configurations inside the `composer.json` file:
```json
{
    "extra": {
        "nexus-push": {
            "url": "http://localhost:8081/repository/composer/",
            "username": "admin",
            "password": "admin123",
            "ignore-dirs": [
                "test",
                "foo"
            ]
        }
    }
}
```

The `username` and `password` can be specified in the `auth.json` file on a per-user basis with the [authentication mechanism provided by Composer](https://getcomposer.org/doc/articles/http-basic-authentication.md).
