# Silverstripe WebP substitution

This module provides a task, `ConvertImagesToWebpTask`, which is to be
run periodically to provide WebP substitutes for all existing public
images.

## Requirements

* silverstripe/framework ^4.4
* Conflicts with **silverstripe/s3** because this module works only with
  the default, filesystem-based asset manager.

## Installation

```sh
composer require quinninteractive/silverstripe-webp-substitution ^1.0.0
```

## License

See [License](LICENSE.md)

## Configuration example

If you should need to change the assets-relative directory or the file
suffix to be used for WebP images, you can do that in the configuration.

```yaml
QuinnInteractive\WebPSub\Task\ConvertImagesToWebpTask:
  webp_directory_path: '.misc/.WebP'
  webp_file_suffix: '.wp'
```

## Command-line example

```sh
sudo -Eu www ./vendor/bin/sake dev/tasks/webpconvert
```

## Crontab example

```crontab
DOCROOT=/var/silverstripe/live/current/docroot
SAKE=./vendor/bin/sake
SUDO=/usr/local/bin/sudo

# WebP graphics conversion task every hour
50 * * * *    (cd $DOCROOT && $SUDO -Eu www $SAKE dev/tasks/webpconvert) > /dev/null 2>&1
```

## Nginx configuration example

To support this module, add these items to your existing Silverstripe
configuration. If you have changed the YAML configuration, you will need
to adjust these items accordingly.

### In the `http` section

```nginx
# webp dir to try, if we accept webp (or none if not)
map $http_accept $webp_dir {
    default   "";
    "~*image/webp"  "/assets/.webp/";
}
```

### In the `server` section, before the main assets `location` directive

```nginx
# first try to return allowed webp
location ~ ^/assets/.*\.(?i:gif|jpeg|jpg|png)$ {
    try_files $webp_dir$uri.webp $uri /index.php?$query_string;
}

# Never serve ^.protected or ^.webp
location ~ ^/assets/\.(webp|protected)/ {
    return 403;
}
```

## Apache configuration example

TBD

## Filesystem preparation

If it can, the task will create the directories that it needs. If not,
you must create the `.webp` directory (or the directory named in the
`webp_directory_path` configuration) under the `assets` directory and
make it writable by the web server.

## Version

1.0.0
