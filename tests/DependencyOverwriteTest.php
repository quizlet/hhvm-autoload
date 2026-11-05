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
 * Dependency packages must not replace symbols defined by the root project.
 *
 * RootImporter builds the root map and then appends every vendor package.
 * Merger keeps the last path for a duplicate class, function, type, constant,
 * enum, or newtype. These tests require the root file to be the one that is
 * emitted and loaded, unless generation throws Facebook\AutoloadMap\Exception
 * (or another Exception) before it writes the autoload map. Two vendor
 * packages that claim the same symbol with different files must be refused
 * the same way, before any map is written.
 *
 * ComposerPlugin::onPostAutoloadDump is not invoked here. It only constructs
 * RootImporter and Writer, which is the path these tests run. Duplicate
 * symbols inside a single package's own roots are also not asserted: that
 * merge cannot by itself replace the root project.
 */
final class DependencyOverwriteTest extends BaseTestCase {
  private Vector<string> $cleanup = Vector {};

  <<__Override>>
  protected function tearDown(): void {
    foreach ($this->cleanup as $dir) {
      $this->removeTree($dir);
    }
    $this->cleanup->clear();
  }

  public function testHhAutoloadDependencyDoesNotReplaceRootSymbols(): void {
    $root = $this->newProject();
    $this->writeRootSymbols($root, 'app');
    $package = $root.'/vendor/evil/hhpkg';
    $this->write($package.'/hh_autoload.json', "{\"roots\":[\"src/\"]}\n");
    $this->writePackageSymbols($package.'/src', 'vendor');

    $this->assertRootSymbolsWin($root);
  }

  public function testComposerFilesDependencyDoesNotReplaceRootSymbols(): void {
    $root = $this->newProject();
    $this->writeRootSymbols($root, 'app');
    $package = $root.'/vendor/evil/filespkg';
    $this->writePackageSymbols($package.'/src', 'vendor');
    $this->write(
      $package.'/composer.json',
      \json_encode(shape(
        'name' => 'evil/filespkg',
        'autoload' => shape(
          'files' => array(
            'src/Auth.php',
            'src/check.php',
            'src/token.php',
            'src/alias.php',
            'src/enum.php',
            'src/newtype.php',
          ),
        ),
      )),
    );

    $this->assertRootSymbolsWin($root);
  }

  public function testComposerClassmapDependencyDoesNotReplaceRootClass(): void {
    $root = $this->newProject();
    $this->writeRootSymbols($root, 'app');
    $package = $root.'/vendor/evil/classmap';
    $this->write($package.'/src/Auth.php', $this->classSource('vendor'));
    // Classmap scanning records classes only. The function in this tree must
    // not become the mapped function either.
    $this->write($package.'/src/check.php', $this->functionSource('vendor'));
    $this->write(
      $package.'/composer.json',
      \json_encode(shape(
        'name' => 'evil/classmap',
        'autoload' => shape(
          'classmap' => array('src/'),
        ),
      )),
    );

    $this->assertRootSymbolsWin($root);
  }

  public function testComposerPsr4DependencyDoesNotReplaceRootClass(): void {
    $root = $this->newProject();
    $this->writeRootSymbols($root, 'app');
    $package = $root.'/vendor/evil/psr4';
    $this->write($package.'/src/Auth.php', $this->classSource('vendor'));
    $this->write(
      $package.'/composer.json',
      \json_encode(array(
        'name' => 'evil/psr4',
        'autoload' => array(
          'psr-4' => array('App\\' => 'src/'),
        ),
      )),
    );

    $this->assertRootSymbolsWin($root);
  }

  public function testComposerPsr0DependencyDoesNotReplaceRootClass(): void {
    $root = $this->newProject();
    $this->writeRootSymbols($root, 'app');
    $package = $root.'/vendor/evil/psr0';
    $this->write($package.'/src/App/Auth.php', $this->classSource('vendor'));
    $this->write(
      $package.'/composer.json',
      \json_encode(array(
        'name' => 'evil/psr0',
        'autoload' => array(
          'psr-0' => array('App\\' => 'src/'),
        ),
      )),
    );

    $this->assertRootSymbolsWin($root);
  }

  public function testCliDoesNotEmitDependencyOverwrite(): void {
    $root = $this->newProject();
    $this->writeRootSymbols($root, 'app');
    $package = $root.'/vendor/evil/hhpkg';
    $this->write($package.'/hh_autoload.json', "{\"roots\":[\"src/\"]}\n");
    $this->writePackageSymbols($package.'/src', 'vendor');

    $dest = $root.'/vendor/hh_autoload.php';
    $this->assertFalse(\file_exists($dest));
    $cwd = \getcwd();
    \chdir($root);
    $cmd = \implode(
      ' ',
      (Vector {
        \PHP_BINARY,
        '-v',
        'Eval.Jit=0',
        \realpath(__DIR__.'/../bin/hh-autoload'),
      })->map($arg ==> \escapeshellarg($arg)),
    );
    $output = array();
    $exit_code = null;
    \exec($cmd, &$output, &$exit_code);
    \chdir($cwd);

    if ($exit_code !== 0) {
      $this->assertFileNotExists($dest);
      $text = \implode("\n", $output);
      $this->assertNotRegExp(
        '/factparse|Failed to parse|does not exist/i',
        $text,
      );
      return;
    }

    $this->assertSame(0, $exit_code, \implode("\n", $output));
    $this->assertRootRuntime($root, $dest);
  }

  public function testTwoDependenciesCannotClaimTheSameSymbols(): void {
    $root = $this->newProject();
    $this->write($root.'/src/Keep.php', $this->classSource('app'));
    foreach (array('aaa/early', 'zzz/late') as $name) {
      $package = $root.'/vendor/'.$name;
      $marker = $name === 'aaa/early' ? 'early' : 'late';
      $this->write($package.'/hh_autoload.json', "{\"roots\":[\"src/\"]}\n");
      $this->write(
        $package.'/src/Crypto.php',
        "<?hh // strict\n".
        "namespace Shared;\n".
        "class Crypto {\n".
        "  public static function marker(): string { return '$marker-class'; }\n".
        "}\n",
      );
      $this->write(
        $package.'/src/check.php',
        "<?hh // strict\n".
        "function shared_check(): string { return '$marker-function'; }\n",
      );
      $this->write(
        $package.'/src/token.php',
        "<?hh // strict\n".
        "const string SHARED_TOKEN = '$marker-constant';\n",
      );
    }

    $dest = $root.'/vendor/hh_autoload.php';
    $importer = new RootImporter($root, IncludedRoots::PROD_ONLY);
    try {
      $map = $importer->getAutoloadMap();
    } catch (\Exception $e) {
      $this->assertFileNotExists($dest);
      $this->assertNotRegExp(
        '/factparse|Failed to parse|does not exist/i',
        $e->getMessage(),
      );
      return;
    }

    $this->fail(
      'generation kept a map when two packages defined the same symbols: '.
      \json_encode(shape(
        'class' => idx($map['class'], 'shared\\crypto'),
        'function' => idx($map['function'], 'shared_check'),
        'constant' => idx($map['constant'], 'SHARED_TOKEN'),
      )),
    );
  }

  public function testUnrelatedDependencySymbolStaysLoadable(): void {
    $root = $this->newProject();
    $this->write($root.'/src/Auth.php', $this->classSource('app'));
    $package = $root.'/vendor/other/lib';
    $this->write($package.'/hh_autoload.json', "{\"roots\":[\"src/\"]}\n");
    $this->write(
      $package.'/src/Payload.php',
      "<?hh // strict\n".
      "namespace Other;\n".
      "class Payload {\n".
      "  public static function marker(): string { return 'other-class'; }\n".
      "}\n",
    );

    $importer = new RootImporter($root, IncludedRoots::PROD_ONLY);
    $map = $importer->getAutoloadMap();
    $this->assertSame(
      \realpath($root.'/src/Auth.php'),
      \realpath($map['class']['app\\auth']),
    );
    $this->assertSame(
      \realpath($package.'/src/Payload.php'),
      \realpath($map['class']['other\\payload']),
    );

    $dest = $root.'/vendor/hh_autoload.php';
    (new Writer())
      ->setBuilder($importer)
      ->setRoot($root)
      ->setRelativeAutoloadRoot(true)
      ->setIsDev(false)
      ->writeToFile($dest);
    $this->assertSame(
      "app-class\nother-class\n",
      $this->execute(
        $root,
        $dest,
        "echo \\App\\Auth::marker(), \"\\n\";\n".
        "echo \\Other\\Payload::marker(), \"\\n\";\n",
      ),
    );
  }

  private function assertRootSymbolsWin(string $root): void {
    $dest = $root.'/vendor/hh_autoload.php';
    $this->assertFalse(\file_exists($dest));
    $importer = new RootImporter($root, IncludedRoots::PROD_ONLY);
    try {
      $map = $importer->getAutoloadMap();
    } catch (\Exception $e) {
      $this->assertFileNotExists($dest);
      $this->assertNotRegExp(
        '/factparse|Failed to parse|does not exist/i',
        $e->getMessage(),
      );
      return;
    }

    $expected = array(
      'class app\\auth' => $root.'/src/Auth.php',
      'class app\\role' => $root.'/src/enum.php',
      'function app_check' => $root.'/src/check.php',
      'function app\\alias_marker' => $root.'/src/alias.php',
      'function app\\newtype_marker' => $root.'/src/newtype.php',
      'type app\\appalias' => $root.'/src/alias.php',
      'type app\\tokenalias' => $root.'/src/newtype.php',
      'constant APP_TOKEN' => $root.'/src/token.php',
    );
    $actual_paths = array();
    $expected_paths = array();
    foreach ($expected as $spec => $file) {
      $space = \strpos($spec, ' ');
      $kind = \substr($spec, 0, $space);
      $name = \substr($spec, $space + 1);
      $actual = idx($map[$kind], $name);
      $actual_paths[$spec] = $actual === null
        ? null
        : \realpath((string)$actual);
      $expected_paths[$spec] = \realpath($file);
    }
    $this->assertSame($expected_paths, $actual_paths);

    (new Writer())
      ->setBuilder($importer)
      ->setRoot($root)
      ->setRelativeAutoloadRoot(true)
      ->setIsDev(false)
      ->writeToFile($dest);
    $this->assertRootRuntime($root, $dest);
  }

  private function assertRootRuntime(string $root, string $generated): void {
    $body =
      "echo \\App\\Auth::marker(), \"\\n\";\n".
      "echo \\App\\Role::USER, \"\\n\";\n".
      "echo app_check(), \"\\n\";\n".
      "echo APP_TOKEN, \"\\n\";\n".
      "\$_alias = 'x' is \\App\\AppAlias;\n".
      "echo \\App\\alias_marker(), \"\\n\";\n".
      "\$_newtype = 'x' is \\App\\TokenAlias;\n".
      "echo \\App\\newtype_marker(), \"\\n\";\n";
    $this->assertSame(
      "app-class\n".
      "app-enum\n".
      "app-function\n".
      "app-constant\n".
      "app-type\n".
      "app-newtype\n",
      $this->execute($root, $generated, $body),
    );
  }

  private function execute(
    string $root,
    string $generated,
    string $body,
  ): string {
    $runner = $root.'/run-autoload.php';
    $this->write($runner, "<?hh\nrequire \$argv[1];\n".$body);
    $cmd = \implode(
      ' ',
      (Vector {
        \PHP_BINARY,
        '-v',
        'Eval.Jit=0',
        $runner,
        $generated,
      })->map($arg ==> \escapeshellarg($arg)),
    );
    $output = array();
    $exit_code = null;
    \exec($cmd, &$output, &$exit_code);
    $this->assertSame(0, $exit_code, \implode("\n", $output));
    return \implode("\n", $output)."\n";
  }

  private function newProject(): string {
    $root = \sys_get_temp_dir().
      '/hh-autoload-overwrite-'.
      \bin2hex(\random_bytes(6));
    \mkdir($root);
    $this->cleanup[] = $root;
    $this->write(
      $root.'/hh_autoload.json',
      "{\"roots\":[\"src/\"]}\n",
    );
    return $root;
  }

  private function writeRootSymbols(string $root, string $marker): void {
    $this->writePackageSymbols($root.'/src', $marker);
  }

  private function writePackageSymbols(string $src, string $marker): void {
    $this->write($src.'/Auth.php', $this->classSource($marker));
    $this->write($src.'/check.php', $this->functionSource($marker));
    $this->write($src.'/token.php', $this->constantSource($marker));
    $this->write($src.'/alias.php', $this->typeSource($marker));
    $this->write($src.'/enum.php', $this->enumSource($marker));
    $this->write($src.'/newtype.php', $this->newtypeSource($marker));
  }

  private function classSource(string $marker): string {
    return
      "<?hh // strict\n".
      "namespace App;\n".
      "class Auth {\n".
      "  public static function marker(): string { return '$marker-class'; }\n".
      "}\n";
  }

  private function functionSource(string $marker): string {
    return
      "<?hh // strict\n".
      "function app_check(): string { return '$marker-function'; }\n";
  }

  private function constantSource(string $marker): string {
    return
      "<?hh // strict\n".
      "const string APP_TOKEN = '$marker-constant';\n";
  }

  private function typeSource(string $marker): string {
    return
      "<?hh // strict\n".
      "namespace App;\n".
      "type AppAlias = string;\n".
      "function alias_marker(): string { return '$marker-type'; }\n";
  }

  private function enumSource(string $marker): string {
    return
      "<?hh // strict\n".
      "namespace App;\n".
      "enum Role: string {\n".
      "  USER = '$marker-enum';\n".
      "}\n";
  }

  private function newtypeSource(string $marker): string {
    return
      "<?hh // strict\n".
      "namespace App;\n".
      "newtype TokenAlias = string;\n".
      "function newtype_marker(): string { return '$marker-newtype'; }\n";
  }

  private function write(string $path, string $contents): void {
    $dir = \dirname($path);
    if (!\is_dir($dir)) {
      \mkdir($dir, 0777, true);
    }
    \file_put_contents($path, $contents);
  }

  private function removeTree(string $path): void {
    if (!\file_exists($path)) {
      return;
    }
    if (!\is_dir($path)) {
      \unlink($path);
      return;
    }
    $items = \scandir($path);
    invariant($items !== false, 'scandir failed');
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      $this->removeTree($path.'/'.$item);
    }
    \rmdir($path);
  }
}
