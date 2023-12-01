<?php

namespace QuinnInteractive\WebPSub\Task;

use Nette\Utils\Finder;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use WebPConvert\Convert\Exceptions\ConversionFailedException;
use WebPConvert\WebPConvert;

class ConvertImagesToWebpTask extends BuildTask
{
    protected $description = "Converts public PNG & JPEG images to WebP for browsers that support it";
    protected $mime_types = [
        'image/png',
        'image/jpeg',
        'image/jpg',
    ];
    protected $title = "Converts public images to webp";
    private array $excluded_absolute_paths = [];
    private static array $exclude_paths = [];
    private static $segment = 'webpconvert';
    private static $size_limit_megapixels = 32;

    public function run($request)
    {
        $start = time();
        set_time_limit(60 * 60);
        $converted = $skipped = $broken = $failed = $general_exception = $too_big = 0;
        $webpdir = $this->webpdir();
        $assetsdir = $this->assetsdir();
        $exclude_paths = $this->config()->get('exclude_paths');
        array_walk($exclude_paths, function (&$value, $index) {
            $value = realpath(sprintf('%s/%s', Director::publicFolder(), $value));
        });
        $this->excluded_absolute_paths = $exclude_paths;
        unset($exclude_paths);
        if (!is_dir($webpdir)) {
            mkdir($webpdir); // TODO permissions?
        }
        $this->header('WebP Dir');
        $this->line($webpdir);

        $this->header('Assets Dir');
        $this->line($assetsdir);

        $this->header('delete any unneeded WebP files');
        foreach (Finder::findFiles('*.webp')->from($webpdir) as $path => $file) {
            // $path is a string containing absolute filename with path
            // $file is an instance of SplFileInfo
            if (is_file($path)) {
                $originalPath = $this->originalImagePath($path);
                if ($this->isExcludedPath($originalPath) || !is_file($originalPath)) {
                    $this->line("{$originalPath} - original missing or excluded; deleting webp file");
                    unlink($path);
                }
            }
        }

        $this->header('convert/update any public images to WebP');
        foreach (Finder::findFiles('*.png', '*.jpg', '*.jpeg')->from($assetsdir)->exclude('.*') as $path => $file) {
            if (is_file($path)) {
                if ($this->isExcludedPath($path)) {
                    $skipped++;
                    continue;
                }
                $relativePath = $this->relativePath($path);
                $mimeType = mime_content_type($path);
                if (!in_array($mimeType, $this->mime_types)) {
                    $this->line("${relativePath}");
                    $this->line("- Wrong MimeType: ${mimeType}");
                    $broken++;
                } else {
                    $size_info = getimagesize($path);
                    if (false !== $size_info) {
                        $megapixels = ($size_info[0] * $size_info[1]) / (1024 * 1024);
                        if ($megapixels > Config::inst()->get(static::class, 'size_limit_megapixels')) {
                            $this->line("${relativePath}");
                            $this->line("- too big: ${megapixels} megapixels");
                            $too_big++;
                            continue;
                        }
                    }
                    $webpPath = $this->webpPath($path);
                    // if the webp file doesn't exist or is newer than the original, create it
                    if (!file_exists($webpPath) || (filemtime($webpPath) < filemtime($path))) {
                        $this->line("- converting: ${relativePath}");
                        $reason = '   !! %s: %s';
                        try {
                            WebPConvert::convert($path, $webpPath);
                            $converted++;
                        } catch (ConversionFailedException $e) {
                            $failed++;
                            $this->line(sprintf($reason, 'conversion failed', $e->getShortMessage()));
                            continue;
                        } catch (\Exception $e) {
                            $general_exception++;
                            $this->line(sprintf($reason, 'general exception', $e->getMessage()));
                            continue;
                        }
                    } else {
                        $skipped++;
                    }
                }
            }
        }

        $end = time();
        $duration = $end - $start;
        $this->header('Done!');
        $this->line('---------------');
        $this->line('duration: ' . $duration . ' seconds');
        $this->line('converted: ' . $converted);
        $this->line('skipped: ' . $skipped);
        $this->line('too big: ' . $too_big);
        $this->line('failed: ' . $failed);
        $this->line('general exceptions: ' . $general_exception);
        $this->line('broken: ' . $broken);

        if (!Director::is_cli()) {
            echo '<p><a href="/dev/tasks">Tasks</a></p>' . "\n";
        }
    }

    private function assetsdir()
    {
        return sprintf('%s/%s', Director::publicFolder(), ASSETS_DIR);
    }

    private function header($string)
    {
        if (Director::is_cli()) {
            echo "\n## ${string} ##\n";
        } else {
            echo "<h2><br />${string}</h2>";
        }
    }

    private function isExcludedPath(string $path): bool
    {
        foreach ($this->excluded_absolute_paths as $excluded_path) {
            if (str_starts_with(realpath($path), $excluded_path)) { // must resolve symlinks!
                return true;
            }
        }
        return false;
    }

    private function line($string)
    {
        if (Director::is_cli()) {
            echo "${string}\n";
        } else {
            echo "${string}<br />";
        }
    }

    private function originalImagePath($path)
    {
        $prefix = $this->webpdir();
        if (0 === strpos($path, $prefix)) {
            $path = substr($path, strlen($prefix));

            // remove the .webp suffix
            $suffix = $this->config()->get('webp_file_suffix');
            if (substr($path, -strlen($suffix)) === $suffix) {
                $path = substr($path, 0, -strlen($suffix));
            }
            return Director::publicFolder() . $path;
        }
        return null;
    }

    private function relativePath($path)
    {
        $prefix = Director::publicFolder();
        if (0 === strpos($path, $prefix)) {
            return substr($path, strlen($prefix));
        }
        return null;
    }

    private function webpdir()
    {
        return sprintf('%s/%s', $this->assetsdir(), $this->config()->get('webp_directory_name'));
    }

    private function webpPath($path)
    {
        $path = $this->relativePath($path);
        return $this->webpdir() . $path . $this->config()->get('webp_file_suffix');
    }
}
