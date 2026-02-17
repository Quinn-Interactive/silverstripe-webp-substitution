<?php

namespace QuinnInteractive\WebPSub\Task;

use Nette\Utils\Finder;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use WebPConvert\Convert\Exceptions\ConversionFailedException;
use WebPConvert\WebPConvert;
use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;

class ConvertImagesToWebpTask extends BuildTask
{
    protected static string $description = "Converts public PNG & JPEG images to WebP for browsers that support it";
    protected $mime_types = [
        'image/png',
        'image/jpeg',
        'image/jpg',
    ];
    protected string $title = "Converts public images to webp";
    private array $excluded_absolute_paths = [];
    private static array $exclude_paths = [];
    protected static string $commandName = 'webpconvert';
    private static $size_limit_megapixels = 32;

    protected function execute(InputInterface $input, PolyOutput $output): int
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
        $output->writeln('WebP Dir');
        $output->writeln($webpdir);

        $output->writeln('Assets Dir');
        $output->writeln($assetsdir);

        $output->writeln('delete any unneeded WebP files');
        foreach (Finder::findFiles('*.webp')->from($webpdir) as $path => $file) {
            // $path is a string containing absolute filename with path
            // $file is an instance of SplFileInfo
            if (is_file($path)) {
                $originalPath = $this->originalImagePath($path) ?? '';
                if ($this->isExcludedPath($originalPath) || !is_file($originalPath)) {
                    $output->writeln("{$originalPath} - original missing or excluded; deleting webp file");
                    unlink($path);
                }
            }
        }

        $output->writeln('convert/update any public images to WebP');
        foreach (Finder::findFiles('*.png', '*.jpg', '*.jpeg')->from($assetsdir)->exclude('.*') as $path => $file) {
            if (is_file($path)) {
                if ($this->isExcludedPath($path)) {
                    $skipped++;
                    continue;
                }
                $relativePath = $this->relativePath($path);
                $mimeType = mime_content_type($path);
                if (!in_array($mimeType, $this->mime_types)) {
                    $output->writeln("{$relativePath}");
                    $output->writeln("- Wrong MimeType: {$mimeType}");
                    $broken++;
                } else {
                    $size_info = getimagesize($path);
                    if (false !== $size_info) {
                        $megapixels = ($size_info[0] * $size_info[1]) / (1024 * 1024);
                        if ($megapixels > Config::inst()->get(static::class, 'size_limit_megapixels')) {
                            $output->writeln("{$relativePath}");
                            $output->writeln("- too big: {$megapixels} megapixels");
                            $too_big++;
                            continue;
                        }
                    }
                    $webpPath = $this->webpPath($path);
                    // if the webp file doesn't exist or is newer than the original, create it
                    if (!file_exists($webpPath) || (filemtime($webpPath) < filemtime($path))) {
                        $output->writeln("- converting: {$relativePath}");
                        $reason = '   !! %s: %s';
                        try {
                            WebPConvert::convert($path, $webpPath);
                            $converted++;
                        } catch (ConversionFailedException $e) {
                            $failed++;
                            $output->writeln(sprintf($reason, 'conversion failed', $e->getShortMessage()));
                            continue;
                        } catch (\Exception $e) { // @phpstan-ignore catch.neverThrown
                            $general_exception++;
                            $output->writeln(sprintf($reason, 'general exception', $e->getMessage()));
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
        $output->writeln('Done!');
        $output->writeln('---------------');
        $output->writeln('duration: ' . $duration . ' seconds');
        $output->writeln('converted: ' . $converted);
        $output->writeln('skipped: ' . $skipped);
        $output->writeln('too big: ' . $too_big);
        $output->writeln('failed: ' . $failed);
        $output->writeln('general exceptions: ' . $general_exception);
        $output->writeln('broken: ' . $broken);

        return 0;
    }

    private function assetsdir()
    {
        return sprintf('%s/%s', Director::publicFolder(), ASSETS_DIR);
    }

    private function isExcludedPath(string $path): bool
    {
        foreach ($this->excluded_absolute_paths as $excluded_path) {
            if (str_starts_with(realpath($path), (string) $excluded_path)) { // must resolve symlinks!
                return true;
            }
        }
        return false;
    }

    private function originalImagePath($path)
    {
        $prefix = $this->webpdir();
        if (str_starts_with((string) $path, (string) $prefix)) {
            $path = substr((string) $path, strlen((string) $prefix));

            // remove the .webp suffix
            $suffix = $this->config()->get('webp_file_suffix');
            if (str_ends_with($path, (string) $suffix)) {
                $path = substr($path, 0, -strlen((string) $suffix));
            }
            return Director::publicFolder() . $path;
        }
        return null;
    }

    private function relativePath($path)
    {
        $prefix = Director::publicFolder();
        if (str_starts_with((string) $path, $prefix)) {
            return substr((string) $path, strlen($prefix));
        }
        return null;
    }

    private function webpdir(): string
    {
        return sprintf('%s/%s', $this->assetsdir(), $this->config()->get('webp_directory_name'));
    }

    private function webpPath($path): string
    {
        $path = $this->relativePath($path);
        return $this->webpdir() . $path . $this->config()->get('webp_file_suffix');
    }
}
