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
 * Dependency-controlled paths that are scheduled for require_once must stay
 * inside that dependency. These tests generate vendor/hh_autoload.php the same
 * way bin/hh-autoload does and then execute that file.
 *
 * They fail while a dependency can require a file that resolves outside its
 * directory and inside the project.
 */
final class ExecFilesPathConfinementTest extends BaseTestCase {
  private Vector<string> $projects = Vector {};

  public function tearDown(): void {
    foreach ($this->projects as $project) {
      $this->removeTree($project);
    }
    $this->projects = Vector {};
    parent::tearDown();
  }

  public function provideRelativeAutoloadRoot(): array<(bool)> {
    return [
      tuple(true),
      tuple(false),
    ];
  }

  /**
   * @dataProvider provideRelativeAutoloadRoot
   */
  public function testExecDoesNotRequireFilesOutsideThePackage(
    bool $relative_root,
  ): void {
    $project = $this->makeProject('exec', $relative_root);
    $package = $project.'/vendor/attacker/pkg';
    \mkdir($package.'/nest', 0777, true);

    $php_marker = $project.'/outside-php-executed';
    $this->writePhpPayload(
      $project.'/outside.php',
      $php_marker,
      'OUTSIDE_PHP_EXECUTED',
    );
    \file_put_contents(
      $project.'/outside.txt',
      "AUTOLOAD_PROJECT_TEXT_MARKER\n",
    );
    \file_put_contents(
      $project.'/vendor/sibling.txt',
      "AUTOLOAD_VENDOR_TEXT_MARKER\n",
    );
    \file_put_contents(
      $package.'/inside.php',
      "<?php\necho \"INSIDE_PACKAGE_EXECUTED\\n\";\n",
    );
    \symlink($project.'/outside.php', $package.'/link-outside.php');
    \symlink($project.'/outside.txt', $package.'/nest/link.txt');

    // Joined with the package directory, every one of these strings starts
    // with that directory. realpath() still leaves the package.
    \file_put_contents(
      $package.'/composer.json',
      \json_encode(shape(
        'autoload' => shape(
          'files' => [
            'inside.php',
            '../../sibling.txt',
            '../../../outside.php',
            '../../../outside.txt',
            'nest/../../../../outside.php',
            'link-outside.php',
            'nest/link.txt',
          ],
        ),
      )),
    );

    $stdout = $this->generateAndExecute($project);
    $this->assertOutsideWasNotLoaded($php_marker, $stdout);
  }

  /**
   * @dataProvider provideRelativeAutoloadRoot
   */
  public function testExecStillRequiresFileInsidePackage(
    bool $relative_root,
  ): void {
    $project = $this->makeProject('exec', $relative_root);
    $package = $project.'/vendor/attacker/pkg';
    \mkdir($package, 0777, true);
    $marker = $project.'/inside-executed';
    $this->writePhpPayload($package.'/inside.php', $marker, 'INSIDE_OK');
    \file_put_contents(
      $package.'/composer.json',
      \json_encode(shape(
        'autoload' => shape(
          'files' => ['inside.php'],
        ),
      )),
    );

    $stdout = $this->generateAndExecute($project);
    $this->assertSame(
      'executed',
      \file_get_contents($marker),
      'A files entry that stays inside the package must still be required',
    );
    $this->assertContains('INSIDE_OK', $stdout);
  }

  /**
   * @dataProvider provideRelativeAutoloadRoot
   */
  public function testScanDoesNotExecuteDependencyFiles(
    bool $relative_root,
  ): void {
    $project = $this->makeProject('scan', $relative_root);
    $package = $project.'/vendor/attacker/pkg';
    \mkdir($package, 0777, true);
    $marker = $project.'/scan-executed';
    $this->writePhpPayload(
      $project.'/outside.php',
      $marker,
      'SCAN_OUTSIDE_EXECUTED',
    );
    $this->writePhpPayload(
      $package.'/inside.php',
      $project.'/scan-inside-executed',
      'SCAN_INSIDE_EXECUTED',
    );
    \file_put_contents(
      $package.'/composer.json',
      \json_encode(shape(
        'autoload' => shape(
          'files' => [
            'inside.php',
            '../../../outside.php',
          ],
        ),
      )),
    );

    $stdout = $this->generateAndExecute($project);
    $this->assertFalse(
      \file_exists($marker),
      'scan behavior must not require a dependency files entry',
    );
    $this->assertFalse(\file_exists($project.'/scan-inside-executed'));
    $this->assertNotContains('SCAN_OUTSIDE_EXECUTED', $stdout);
    $this->assertNotContains('SCAN_INSIDE_EXECUTED', $stdout);
  }

  /**
   * @dataProvider provideRelativeAutoloadRoot
   */
  public function testDependencyExtraFilesStayInsideThePackage(
    bool $relative_root,
  ): void {
    $project = $this->makeProject('scan', $relative_root);
    $package = $project.'/vendor/attacker/pkg';
    \mkdir($package, 0777, true);
    $php_marker = $project.'/extra-php-executed';
    $this->writePhpPayload(
      $project.'/outside.php',
      $php_marker,
      'EXTRA_OUTSIDE_PHP_EXECUTED',
    );
    \file_put_contents(
      $project.'/outside.txt',
      "AUTOLOAD_EXTRA_TEXT_MARKER\n",
    );
    \file_put_contents(
      $package.'/inside.php',
      "<?php\necho \"EXTRA_INSIDE_EXECUTED\\n\";\n",
    );
    \file_put_contents(
      $package.'/hh_autoload.json',
      \json_encode(shape(
        'roots' => [],
        'extraFiles' => [
          'inside.php',
          '../../../outside.php',
          'nest/../../../../outside.txt',
          $project.'/outside.php',
        ],
      )),
    );
    \mkdir($package.'/nest');

    $stdout = $this->generateAndExecute($project);
    $this->assertOutsideWasNotLoaded($php_marker, $stdout);
    $this->assertNotContains('AUTOLOAD_EXTRA_TEXT_MARKER', $stdout);
  }

  public function testExecDoesNotRequireFileOutsideTheProject(): void {
    $project = $this->makeProject('exec', true);
    $package = $project.'/vendor/attacker/pkg';
    \mkdir($package, 0777, true);
    $outside_dir = \sys_get_temp_dir().'/hh-autoload-outside-project-'.
      \bin2hex(\random_bytes(4));
    $this->projects[] = $outside_dir;
    \mkdir($outside_dir, 0777, true);
    $marker = $outside_dir.'/executed';
    $this->writePhpPayload(
      $outside_dir.'/payload.php',
      $marker,
      'OUTSIDE_PROJECT_EXECUTED',
    );
    $relative = $this->relativePathFrom($package, $outside_dir.'/payload.php');
    \file_put_contents(
      $package.'/composer.json',
      \json_encode(shape(
        'autoload' => shape(
          'files' => [$relative],
        ),
      )),
    );

    $stdout = $this->generateAndExecute($project);
    $this->assertFalse(\file_exists($marker));
    $this->assertNotContains('OUTSIDE_PROJECT_EXECUTED', $stdout);
  }

  private function makeProject(string $behavior, bool $relative_root): string {
    $project = \sys_get_temp_dir().'/hh-autoload-confinement-'.
      \bin2hex(\random_bytes(4));
    \mkdir($project.'/vendor', 0777, true);
    \file_put_contents(
      $project.'/hh_autoload.json',
      \json_encode(shape(
        'roots' => [],
        'autoloadFilesBehavior' => $behavior,
        'includeVendor' => true,
        'relativeAutoloadRoot' => $relative_root,
      )),
    );
    $this->projects[] = $project;
    return $project;
  }

  private function writePhpPayload(
    string $path,
    string $marker,
    string $token,
  ): void {
    \file_put_contents(
      $path,
      "<?php\n".
      "file_put_contents(".\var_export($marker, true).", 'executed');\n".
      "echo ".\var_export($token."\n", true).";\n",
    );
  }

  /**
   * Generate vendor/hh_autoload.php from this project and execute it.
   * Returns captured stdout. Generation may refuse; execution must not have
   * produced the caller's outside-file side effects either way.
   */
  private function generateAndExecute(string $project): string {
    $generated = $project.'/vendor/hh_autoload.php';
    $bin = \realpath(__DIR__.'/../bin/hh-autoload');
    $this->assertInternalType('string', $bin);

    $command = \implode(
      ' ',
      (Vector {
        \PHP_BINARY,
        '-v',
        'Eval.Jit=0',
        $bin,
      })->map($arg ==> \escapeshellarg($arg)),
    );
    $command = 'cd '.\escapeshellarg($project).' && '.$command;

    $output = [];
    $exit_code = null;
    \exec($command, &$output, &$exit_code);
    if (!\file_exists($generated)) {
      // Refusing to write the map is a successful confinement only when the
      // generator did not already include the targeted file. Callers assert
      // those side effects.
      return \implode("\n", $output);
    }

    $run_output = [];
    $run_exit = null;
    $run = \implode(
      ' ',
      (Vector {
        \PHP_BINARY,
        '-v',
        'Eval.Jit=0',
        $generated,
      })->map($arg ==> \escapeshellarg($arg)),
    );
    \exec($run, &$run_output, &$run_exit);
    return \implode("\n", $run_output);
  }

  private function assertOutsideWasNotLoaded(
    string $php_marker,
    string $stdout,
  ): void {
    $this->assertFalse(
      \file_exists($php_marker),
      'A dependency path outside the package was executed',
    );
    $this->assertNotContains('OUTSIDE_PHP_EXECUTED', $stdout);
    $this->assertNotContains('AUTOLOAD_PROJECT_TEXT_MARKER', $stdout);
    $this->assertNotContains('AUTOLOAD_VENDOR_TEXT_MARKER', $stdout);
    $this->assertNotContains('EXTRA_OUTSIDE_PHP_EXECUTED', $stdout);
  }

  private function relativePathFrom(string $from_dir, string $target): string {
    $from = \explode('/', \rtrim($from_dir, '/'));
    $to = \explode('/', \rtrim($target, '/'));
    while (\count($from) > 0 && \count($to) > 0 && $from[0] === $to[0]) {
      $from = \array_slice($from, 1);
      $to = \array_slice($to, 1);
    }
    $up = \array_fill(0, \count($from), '..');
    return \implode('/', \array_merge($up, $to));
  }

  private function removeTree(string $path): void {
    if (\is_link($path)) {
      \unlink($path);
      return;
    }
    if (\is_file($path)) {
      \unlink($path);
      return;
    }
    if (!\is_dir($path)) {
      return;
    }
    foreach (new \DirectoryIterator($path) as $item) {
      if ($item->isDot()) {
        continue;
      }
      $this->removeTree($item->getPathname());
    }
    \rmdir($path);
  }
}
