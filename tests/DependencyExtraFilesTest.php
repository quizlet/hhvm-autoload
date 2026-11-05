<?hh // strict
/*
 *  Copyright (c) 2015-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

namespace Facebook\AutoloadMap;

/**
 * A dependency must not cause vendor/hh_autoload.php to require a file that
 * lives outside that dependency's directory.
 *
 * These tests follow the production path used by ComposerPlugin and
 * bin/hh-autoload: RootImporter, then Writer::writeToFile(). They fail while
 * escaped extraFiles (and escaped composer "files" when behavior is exec)
 * are still written as require_once.
 */
final class DependencyExtraFilesTest extends BaseTestCase {
  const string ENV_MARKER = "OUTSIDE_ENV_MARKER\n";
  const string VENDOR_ENV_MARKER = "VENDOR_ENV_MARKER\n";
  const string AUTH_MARKER = "OUTSIDE_AUTH_MARKER\n";
  const string EXEC_MARKER = 'OUTSIDE_EXEC_MARKER';
  const string BOUNDARY_MARKER = "BOUNDARY_MARKER\n";
  const string INSIDE_MARKER = 'INSIDE_PACKAGE_MARKER';
  const string ROOT_MARKER = 'ROOT_EXTRA_MARKER';
  const string SYMLINK_TARGET_MARKER = 'SYMLINK_TARGET_MARKER';

  private ?string $project = null;

  public function tearDown(): void {
    $project = $this->project;
    if ($project !== null && \is_dir($project)) {
      $this->removeTree($project);
    }
    $this->project = null;
    parent::tearDown();
  }

  public function provideImportModes(): array<(IncludedRoots, bool)> {
    return [
      tuple(IncludedRoots::PROD_ONLY, true),
      tuple(IncludedRoots::PROD_ONLY, false),
      tuple(IncludedRoots::DEV_AND_PROD, true),
      tuple(IncludedRoots::DEV_AND_PROD, false),
    ];
  }

  /**
   * @dataProvider provideImportModes
   */
  public function testDependencyExtraFilesCannotRequireFilesOutsideThePackage(
    IncludedRoots $included,
    bool $relative_root,
  ): void {
    $project = $this->createProject($relative_root);
    $package = $project.'/vendor/evil/pkg';
    \mkdir($package.'/foo', 0777, true);
    \symlink('../../../.env', $package.'/escape-link.txt');

    $env = \realpath($project.'/.env');
    $exec = \realpath($project.'/outside-exec.php');
    invariant($env is string && $exec is string, 'fixture files exist');

    // vendor/*/*/ is three directories below the project root. Glob keeps the
    // trailing slash, and HHImporter appends another slash, but both still
    // resolve these relatives to the same files.
    $extra_files = [
      '../../.env',
      '../../../.env',
      '../../../auth.json',
      '../../../outside-exec.php',
      '../pkg-sibling/boundary.txt',
      'escape-link.txt',
      'foo/../../../../.env',
      $env,
      $exec,
    ];
    $this->assertPathsResolveOutsidePackage($package, $extra_files);
    $this->writeJson(
      $package.'/hh_autoload.json',
      [
        'roots' => ['src/'],
        'extraFiles' => $extra_files,
      ],
    );
    $this->writeJson(
      $package.'/composer.json',
      ['name' => 'evil/pkg'],
    );
    $this->writeFile($package.'/src/Dep.php', "<?hh\nclass EvilDep {}\n");

    $result = $this->generate($project, $included);
    $this->assertOutsideFilesNotRequired($project, $result);
    $this->assertRootExtraStillRequiredIfGenerated($result);
  }

  /**
   * @dataProvider provideImportModes
   */
  public function testDependencyExtraFileInsideThePackageIsStillRequired(
    IncludedRoots $included,
    bool $relative_root,
  ): void {
    $project = $this->createProject($relative_root);
    $package = $project.'/vendor/evil/pkg';
    $this->writeJson(
      $package.'/hh_autoload.json',
      [
        'roots' => ['src/'],
        'extraFiles' => ['inside.php'],
      ],
    );
    $this->writeJson($package.'/composer.json', ['name' => 'evil/pkg']);
    $this->writeFile($package.'/src/Dep.php', "<?hh\nclass EvilDep {}\n");
    $this->writeFile(
      $package.'/inside.php',
      "<?hh\necho \"".self::INSIDE_MARKER."\\n\";\n",
    );

    $result = $this->generate($project, $included);
    $this->assertNull($result['error'], $result['error'] ?? '');
    $generated = $result['generated'];
    invariant($generated is string, 'autoload file was written');
    $output = $this->executeGenerated($generated);
    $this->assertContains(self::INSIDE_MARKER, $output, $output);
    $this->assertContains(self::ROOT_MARKER, $output, $output);

    $inside = \realpath($package.'/inside.php');
    invariant($inside is string, 'inside.php exists');
    $this->assertContains($inside, $this->requiredRealpaths($generated));
    $this->assertTrue($this->isInsideDirectory($inside, $package));
  }

  public function testSymlinkRetargetedAfterGenerationIsNotRequired(): void {
    $project = $this->createProject(true);
    $package = $project.'/vendor/evil/pkg';
    $this->writeJson(
      $package.'/hh_autoload.json',
      [
        'roots' => ['src/'],
        'extraFiles' => ['alias.php'],
      ],
    );
    $this->writeJson($package.'/composer.json', ['name' => 'evil/pkg']);
    $this->writeFile($package.'/src/Dep.php', "<?hh\nclass EvilDep {}\n");
    $this->writeFile(
      $package.'/symlink-target.php',
      "<?hh\necho \"".self::SYMLINK_TARGET_MARKER."\\n\";\n",
    );
    \symlink('symlink-target.php', $package.'/alias.php');

    $result = $this->generate($project, IncludedRoots::PROD_ONLY);
    $this->assertNull($result['error'], $result['error'] ?? '');
    $generated = $result['generated'];
    invariant($generated is string, 'autoload file was written');

    \unlink($package.'/alias.php');
    \symlink('../../../.env', $package.'/alias.php');

    $paths = $this->requiredRealpaths($generated);
    $env = \realpath($project.'/.env');
    invariant($env is string, '.env exists');
    $this->assertNotContains(
      $env,
      $paths,
      "require path was resolved again from the symlink\n".
      \file_get_contents($generated),
    );
    $output = $this->executeGenerated($generated);
    $this->assertNotContains(self::ENV_MARKER, $output, $output);
    $this->assertNotContains('OUTSIDE_ENV_MARKER', $output, $output);
    $this->assertContains(self::SYMLINK_TARGET_MARKER, $output, $output);
    $this->assertSame(self::ENV_MARKER, \file_get_contents($project.'/.env'));
  }

  public function testComposerExecFilesCannotRequireFilesOutsideThePackage(
  ): void {
    $project = $this->createProject(true, 'exec');
    $package = $project.'/vendor/evil/pkg';
    \mkdir($package.'/foo', 0777, true);
    \symlink('../../../.env', $package.'/escape-link.txt');
    $extra_files = [
      '../../.env',
      '../../../.env',
      '../../../auth.json',
      '../../../outside-exec.php',
      '../pkg-sibling/boundary.txt',
      'escape-link.txt',
      'foo/../../../../.env',
    ];
    $this->assertPathsResolveOutsidePackage($package, $extra_files);
    $this->writeJson(
      $package.'/composer.json',
      [
        'name' => 'evil/pkg',
        'autoload' => ['files' => $extra_files],
      ],
    );

    $result = $this->generate($project, IncludedRoots::PROD_ONLY);
    $this->assertOutsideFilesNotRequired($project, $result);
  }

  public function testComposerExecFileInsideThePackageIsStillRequired(): void {
    $project = $this->createProject(true, 'exec');
    $package = $project.'/vendor/evil/pkg';
    $this->writeJson(
      $package.'/composer.json',
      [
        'name' => 'evil/pkg',
        'autoload' => ['files' => ['inside.php']],
      ],
    );
    $this->writeFile(
      $package.'/inside.php',
      "<?hh\necho \"".self::INSIDE_MARKER."\\n\";\n",
    );

    $result = $this->generate($project, IncludedRoots::PROD_ONLY);
    $this->assertNull($result['error'], $result['error'] ?? '');
    $generated = $result['generated'];
    invariant($generated is string, 'autoload file was written');
    $output = $this->executeGenerated($generated);
    $this->assertContains(self::INSIDE_MARKER, $output, $output);
    $inside = \realpath($package.'/inside.php');
    invariant($inside is string, 'inside.php exists');
    $this->assertContains($inside, $this->requiredRealpaths($generated));
    $this->assertTrue($this->isInsideDirectory($inside, $package));
  }

  public function testComposerScanDoesNotRequireFilesOutsideThePackage(
  ): void {
    $project = $this->createProject(true, 'scan');
    $package = $project.'/vendor/evil/pkg';
    \symlink('../../../.env', $package.'/escape-link.txt');
    $extra_files = [
      '../../.env',
      '../../../.env',
      '../../../auth.json',
      '../../../outside-exec.php',
      '../pkg-sibling/boundary.txt',
      'escape-link.txt',
    ];
    $this->assertPathsResolveOutsidePackage($package, $extra_files);
    $this->writeJson(
      $package.'/composer.json',
      [
        'name' => 'evil/pkg',
        'autoload' => ['files' => $extra_files],
      ],
    );

    $result = $this->generate($project, IncludedRoots::PROD_ONLY);
    $this->assertOutsideFilesNotRequired($project, $result);
  }

  private function createProject(
    bool $relative_root,
    string $files_behavior = 'scan',
  ): string {
    $project = \sys_get_temp_dir().'/hh-autoload-extra-'.\bin2hex(\random_bytes(4));
    $this->project = $project;
    \mkdir($project.'/src', 0777, true);
    \mkdir($project.'/vendor/evil/pkg/src', 0777, true);
    \mkdir($project.'/vendor/evil/pkg-sibling', 0777, true);

    $root_config = [
      'roots' => ['src/'],
      'extraFiles' => ['root-extra.php'],
      'autoloadFilesBehavior' => $files_behavior,
    ];
    if (!$relative_root) {
      $root_config['relativeAutoloadRoot'] = false;
    }
    $this->writeJson($project.'/hh_autoload.json', $root_config);
    $this->writeFile($project.'/src/App.php', "<?hh\nclass ProjectApp {}\n");
    $this->writeFile(
      $project.'/root-extra.php',
      "<?hh\necho \"".self::ROOT_MARKER."\\n\";\n",
    );
    $this->writeFile($project.'/.env', self::ENV_MARKER);
    $this->writeFile($project.'/auth.json', self::AUTH_MARKER);
    $this->writeFile($project.'/vendor/.env', self::VENDOR_ENV_MARKER);
    $this->writeFile(
      $project.'/outside-exec.php',
      "<?hh\necho \"".self::EXEC_MARKER."\\n\";\n",
    );
    $this->writeFile(
      $project.'/vendor/evil/pkg-sibling/boundary.txt',
      self::BOUNDARY_MARKER,
    );
    return $project;
  }

  private function generate(
    string $project,
    IncludedRoots $included,
  ): shape(
    'generated' => ?string,
    'during' => string,
    'error' => ?string,
  ) {
    $generated = $project.'/vendor/hh_autoload.php';
    \ob_start();
    $error = null;
    try {
      $importer = new RootImporter($project, $included);
      $is_dev = $included === IncludedRoots::DEV_AND_PROD;
      $config = $importer->getConfig();
      $handler = $is_dev
        ? $config['devFailureHandler']
        : $config['failureHandler'];
      (new Writer())
        ->setBuilder($importer)
        ->setRoot($project)
        ->setRelativeAutoloadRoot($config['relativeAutoloadRoot'])
        ->setFailureHandler($handler)
        ->setIsDev($is_dev)
        ->writeToFile($generated);
    } catch (\Exception $exception) {
      $error = $exception->getMessage();
    }
    $during = \ob_get_clean();
    invariant(\is_string($during), 'output buffer returned a string');
    return shape(
      'generated' => \file_exists($generated) ? $generated : null,
      'during' => $during,
      'error' => $error,
    );
  }

  private function assertOutsideFilesNotRequired(
    string $project,
    shape(
      'generated' => ?string,
      'during' => string,
      'error' => ?string,
    ) $result,
  ): void {
    $env = $project.'/.env';
    $vendor_env = $project.'/vendor/.env';
    $auth = $project.'/auth.json';
    $exec = $project.'/outside-exec.php';
    $boundary = $project.'/vendor/evil/pkg-sibling/boundary.txt';
    $this->assertSame(self::ENV_MARKER, \file_get_contents($env));
    $this->assertSame(self::VENDOR_ENV_MARKER, \file_get_contents($vendor_env));
    $this->assertSame(self::AUTH_MARKER, \file_get_contents($auth));
    $this->assertContains(self::EXEC_MARKER, \file_get_contents($exec));
    $this->assertSame(self::BOUNDARY_MARKER, \file_get_contents($boundary));
    $this->assertNotContains('OUTSIDE_ENV_MARKER', $result['during']);
    $this->assertNotContains('VENDOR_ENV_MARKER', $result['during']);
    $this->assertNotContains('OUTSIDE_AUTH_MARKER', $result['during']);
    $this->assertNotContains(self::EXEC_MARKER, $result['during']);
    $this->assertNotContains('BOUNDARY_MARKER', $result['during']);

    $generated = $result['generated'];
    if ($generated === null) {
      return;
    }

    $contents = \file_get_contents($generated);
    $output = $this->executeGenerated($generated);
    $combined = $contents."\n".$output;
    $this->assertNotContains('OUTSIDE_ENV_MARKER', $output, $combined);
    $this->assertNotContains('VENDOR_ENV_MARKER', $output, $combined);
    $this->assertNotContains('OUTSIDE_AUTH_MARKER', $output, $combined);
    $this->assertNotContains(self::EXEC_MARKER, $output, $combined);
    $this->assertNotContains('BOUNDARY_MARKER', $output, $combined);
    $forbidden = [
      \realpath($env),
      \realpath($vendor_env),
      \realpath($auth),
      \realpath($exec),
      \realpath($boundary),
    ];
    foreach ($this->requiredRealpaths($generated) as $path) {
      $this->assertNotContains(
        $path,
        $forbidden,
        "require_once resolved to {$path}\n{$contents}",
      );
    }
  }

  private function assertPathsResolveOutsidePackage(
    string $package,
    array<string> $paths,
  ): void {
    foreach ($paths as $path) {
      $joined = $path[0] === '/' ? $path : $package.'/'.$path;
      $real = \realpath($joined);
      $this->assertTrue(
        \is_string($real) && $real !== '',
        "fixture path {$path} did not resolve from {$package}",
      );
      $this->assertFalse(
        $this->isInsideDirectory($real, $package),
        "fixture path {$path} stayed inside {$package}: {$real}",
      );
    }
  }

  private function assertRootExtraStillRequiredIfGenerated(
    shape(
      'generated' => ?string,
      'during' => string,
      'error' => ?string,
    ) $result,
  ): void {
    $generated = $result['generated'];
    if ($generated === null) {
      return;
    }
    $output = $this->executeGenerated($generated);
    $this->assertContains(
      self::ROOT_MARKER,
      $output,
      "root extraFiles was dropped\n".\file_get_contents($generated)."\n".$output,
    );
  }

  private function requiredRealpaths(string $generated): array<string> {
    $contents = \file_get_contents($generated);
    $matches = [];
    \preg_match_all('/require_once\((.*)\);/', $contents, &$matches);
    $arguments = $matches[1] ?? [];
    $dir = \dirname($generated);
    $paths = [];
    foreach ($arguments as $argument) {
      invariant(\is_string($argument), 'require argument is a string');
      $paths[] = $this->resolveRequireArgument($argument, $dir);
    }
    return $paths;
  }

  private function resolveRequireArgument(
    string $argument,
    string $generated_dir,
  ): string {
    $argument = \trim($argument);
    $prefix = "__DIR__.'";
    if (
      \substr($argument, 0, \strlen($prefix)) === $prefix &&
      \substr($argument, -1) === "'"
    ) {
      $relative = \substr(
        $argument,
        \strlen($prefix),
        \strlen($argument) - \strlen($prefix) - 1,
      );
      $path = $generated_dir.\stripcslashes($relative);
      $real = \realpath($path);
      return $real === false ? $path : $real;
    }
    if ($argument !== '' && $argument[0] === "'" && \substr($argument, -1) === "'") {
      $path = \stripcslashes(\substr($argument, 1, \strlen($argument) - 2));
      $real = \realpath($path);
      return $real === false ? $path : $real;
    }
    return $argument;
  }

  private function executeGenerated(string $generated): string {
    $runner = \tempnam(\sys_get_temp_dir(), 'hh-autoload-run');
    invariant($runner is string, 'temp runner path');
    \file_put_contents(
      $runner,
      "<?hh\nrequire_once(".\var_export($generated, true).");\n",
    );
    $cmd = \implode(
      ' ',
      (Vector { \PHP_BINARY, '-v', 'Eval.Jit=0', $runner })
        ->map($part ==> \escapeshellarg($part)),
    ).' 2>&1';
    $output = [];
    $exit_code = null;
    \exec($cmd, &$output, &$exit_code);
    \unlink($runner);
    return \implode("\n", $output)."\nexit=".\strval($exit_code);
  }

  private function isInsideDirectory(string $path, string $directory): bool {
    $directory = \realpath($directory);
    $path = \realpath($path);
    if (!\is_string($directory) || !\is_string($path)) {
      return false;
    }
    if (\substr($directory, -1) === '/') {
      $directory = \substr($directory, 0, -1);
    }
    return $path === $directory || \strpos($path, $directory.'/') === 0;
  }

  private function writeJson(string $path, array<string, mixed> $data): void {
    $encoded = \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    invariant(\is_string($encoded), 'json_encode failed');
    $this->writeFile($path, $encoded."\n");
  }

  private function writeFile(string $path, string $contents): void {
    $directory = \dirname($path);
    if (!\is_dir($directory)) {
      \mkdir($directory, 0777, true);
    }
    \file_put_contents($path, $contents);
  }

  private function removeTree(string $dir): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(
        $dir,
        \FilesystemIterator::SKIP_DOTS,
      ),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $info) {
      $path = $info->getPathname();
      if ($info->isLink() || !$info->isDir()) {
        \unlink($path);
      } else {
        \rmdir($path);
      }
    }
    \rmdir($dir);
  }
}
