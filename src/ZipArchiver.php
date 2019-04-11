<?php


namespace Elendev\NexusComposerPush;

use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class ZipArchiver
{

    /**
     * Archive the given directory in the $destination file
     *
     * @param string $source archive source
     * @param string $destination archive destination
     * @param string $subDirectory subdirectory in which the sources will be
     *               archived. If null, put at the root of the directory.
     * @param array $ignorePatterns
     *
     * @param OutputInterface|null $io
     *
     * @throws \Exception
     */
    public static function archiveDirectory(
        $source,
        $destination,
        $subDirectory = null,
        $ignorePatterns = [],
        $io = null
    ) {
        if (empty($io)) {
            $io = new NullOutput();
        }

        if ($subDirectory) {
            $io->writeln('[ZIP Archive] Archive into the subdirectory ' . $subDirectory);
        } else {
            $io->writeln('[ZIP Archive] Archive into root directory');
        }

        $finder = new Finder();
        $fileSystem = new Filesystem();

        $finder->in($source)->ignoreVCS(true);

        foreach ($ignorePatterns as $ignorePattern) {
            $finder->notPath($ignorePattern);
        }

        $archive = new \ZipArchive();

        $io->write(
            'Create ZIP file ' . $destination,
            true,
            OutputInterface::VERBOSITY_VERY_VERBOSE
      );

        if (!$archive->open($destination, \ZipArchive::CREATE)) {
            $io->write(
                '<error>Impossible to create ZIP file ' . $destination . '</error>',
                true
          );
            throw new \Exception('Impossible to create the file ' . $destination);
        }

        foreach ($finder as $fileInfo) {
            if ($subDirectory) {
                $zipPath = $subDirectory . '/';
            } else {
                $zipPath = '';
            }

            $zipPath .= rtrim($fileSystem->makePathRelative(
                $fileInfo->getRealPath(),
                $source
          ), '/');

            if (!$fileInfo->isFile()) {
                continue;
            }

            $io->write(
                'Zip file ' . $fileInfo->getPath() . ' to ' . $zipPath,
                true,
                OutputInterface::VERBOSITY_VERY_VERBOSE
          );
            $archive->addFile($fileInfo->getRealPath(), $zipPath);
        }

        $io->writeln('Zip archive ' . $destination . ' done');
        $archive->close();
    }
}
