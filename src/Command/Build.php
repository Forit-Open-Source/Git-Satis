<?php

namespace Forit\GitSatis\Command;

use Gitonomy\Git\Admin;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Tag;
use Gitonomy\Git\Repository;
use Iterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

class Build extends Command
{
    protected static $defaultName = 'build';

    /** @var array<mixed> */
    protected $packageJson;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setDescription('Build repository from git (https required)');
        $this->addArgument('repo-uri', InputArgument::REQUIRED, 'Repository URI (https with credentials)');
        $this->addArgument(
            'public-uri',
            InputArgument::REQUIRED,
            'Repository public path (eg. https://satis.company.com)'
        );
        $this->addArgument('out', InputArgument::OPTIONAL, 'Output directory path (without trailing slash)', 'out');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Argument validation
        $repoUri = $input->getArgument('repo-uri');
        if (!is_string($repoUri)) {
            $output->writeln('<fg=red>Repository URI must be string</>');
            return 1;
        }
        $outPath = $input->getArgument('out');
        if (!is_string($outPath)) {
            $output->writeln('<fg=red>Output directory path must be string</>');
            return 1;
        }
        $publicUri = $input->getArgument('public-uri');
        if (!is_string($publicUri)) {
            $output->writeln('<fg=red>Public URI must be string</>');
            return 1;
        }

        // Create output directory (if not exists)
        if (!file_exists($outPath)) {
            mkdir($outPath, 0755, true);
        }

        // Temporary directory for git clone
        $tmpDir = sys_get_temp_dir();
        $repoPath = $tmpDir . tempnam($tmpDir, 'git-satis');

        // Clone repository
        $repo = Admin::cloneTo($repoPath, $repoUri, false);

        // Process all tags and branches
        $this->packageJson = [];

        // Load existing packages.json (if exists)
        $packagesJsonPath = $outPath . '/packages.json';
        if (file_exists($packagesJsonPath)) {
            $packageJson = file_get_contents($packagesJsonPath);
            if ($packageJson !== false) {
                $packageJson = json_decode($packageJson, true);
                if (is_array($packageJson)) {
                    $this->packageJson = $packageJson;
                }
            }
        }
        if (!array_key_exists('packages', $this->packageJson)) {
            $this->packageJson['packages'] = [];
        }

        /** @var Tag[] $tags */
        $tags = $repo->getReferences()->getTags();
        foreach ($tags as $tag) {
            $this->package($repo, $tag->getName(), $tag->getName(), $tag->getCommitHash(), $outPath, $publicUri);
        }

        /** @var Branch[] $branches */
        $branches = $repo->getReferences()->getRemoteBranches();
        foreach ($branches as $branch) {
            $this->package(
                $repo,
                $branch->getName(),
                'dev-' . str_replace('origin/', '', $branch->getName()),
                $branch->getCommitHash(),
                $outPath,
                $publicUri
            );
        }

        // Save packages.json
        file_put_contents($packagesJsonPath, json_encode($this->packageJson, JSON_PRETTY_PRINT));

        // Remove temp directory
        shell_exec('rm -rf ' . escapeshellarg($repoPath));

        return 0;
    }

    protected function package(
        Repository $repo,
        string $revision,
        string $version,
        string $commitHash,
        string $outPath,
        string $publicUri
    ): void {
        // Checkout branch/tag
        $repo->getWorkingCopy()->checkout($revision);

        // Find all composer.json
        $composerJsons = $this->findComposerJson($repo->getWorkingDir());
        foreach ($composerJsons as $jsonFilePath) {
            $json = file_get_contents($jsonFilePath);
            if ($json === false) {
                continue;
            }
            $json = json_decode($json, true);
            if (!is_array($json) || !array_key_exists('name', $json)) {
                continue;
            }

            // Build ZIP file from directory
            $zipPublicPath = $publicUri . '/dist/' . $json['name'] . '-' . $commitHash . '.zip';
            $zipPath = $outPath . '/dist/' . $json['name'] . '-' . $commitHash . '.zip';
            if (!file_exists($zipPath)) {
                // File for commit was not generated yet, create it

                // Ensure directory exists (for scoped packages)
                $zipDir = dirname($zipPath);
                if (!file_exists($zipDir)) {
                    mkdir($zipDir, 0755, true);
                }

                // Package path (root of composer.json)
                $packagePath = dirname($jsonFilePath);

                // Create zip with package
                $zip = new ZipArchive();
                $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                /** @var Iterator<SplFileInfo> $files */
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($packagePath),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    // Skip directories (they would be added automatically)
                    if (!$file->isDir()) {
                        // Get real and relative path for current file
                        $filePath = $file->getRealPath();
                        if ($filePath === false) {
                            continue;
                        }
                        $relativePath = substr($filePath, strlen($packagePath) + 1);
                        // Add current file to archive
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                $zip->close();
            }

            // Add version and dist path to packages.json
            if (!array_key_exists($json['name'], $this->packageJson['packages'])) {
                $this->packageJson['packages'][$json['name']] = [];
            }

            $json['version'] = $version;
            $json['dist'] = [
                'url' => $zipPublicPath,
                'type' => 'zip'
            ];
            $this->packageJson['packages'][$json['name']][$version] = $json;
        }
    }

    /**
     * Find all composer.json files in directory
     *
     * @param string $path
     * @return string[]
     */
    protected function findComposerJson(string $path): array
    {
        $paths = [];

        $dir = new RecursiveDirectoryIterator($path);
        $files = new RecursiveIteratorIterator($dir);
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir() || $file->getFilename() !== 'composer.json') {
                continue;
            }
            $paths[] = $file->getPathname();
        }

        return $paths;
    }
}
