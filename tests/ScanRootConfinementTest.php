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
 * Dependency scan roots must stay inside the package being imported.
 *
 * A root is confined only after it is resolved: absolute paths, `..`
 * segments, and a root that is a symlink to another directory are outside
 * the package. Generation must still map files from roots that do resolve
 * inside the package.
 *
 * One scan root records at most ScanRootConfinementTest::MAX_SCANNED_FILES
 * `.php`, `.hh`, and `.xhp` files. An unreadable directory is skipped.
 * Generation finishes without throwing, and it does not create or delete
 * files in the tree it was asked to scan.
 */
final class ScanRootConfinementTest extends BaseTestCase {
  const int MAX_SCANNED_FILES = 4096;

  private string $work = '';

  public function setUp(): void {
    $this->work = \sys_get_temp_dir().
      '/hh-autoload-scan-'.
      \bin2hex(\random_bytes(6));
    \mkdir($this->work, 0700);
  }

  public function tearDown(): void {
    $this->removeTree($this->work);
  }

  public function testHhAbsoluteRootStaysInsidePackage(): void {
    $package = $this->work.'/pkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($package.'/src/InsideOnly.php', 'InsideOnly');
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    $this->writeHackClass($outside.'/OutsideHh.hh', 'OutsideHh');
    $this->writeXhpClass($outside.'/Widget.xhp', 'scan', 'widget');
    $this->writeHhConfig(
      $package,
      ImmVector { 'src', $outside },
      ImmVector { },
    );
    $before = $this->filesUnder($outside);

    $map = $this->hhMap($package, IncludedRoots::PROD_ONLY);

    $this->assertSame($before, $this->filesUnder($outside));
    $this->assertArrayHasKey('insideonly', $map['class']);
    $this->assertMapStaysInside($map, $package);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
    $this->assertArrayNotHasKey('outsidehh', $map['class']);
    $this->assertArrayNotHasKey('xhp_scan__widget', $map['class']);
  }

  public function testHhParentSegmentsStayInsidePackage(): void {
    $package = $this->work.'/pkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($package.'/src/InsideOnly.php', 'InsideOnly');
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    $this->writeHhConfig(
      $package,
      ImmVector { 'src', 'src/../../outside' },
      ImmVector { },
    );

    $map = $this->hhMap($package, IncludedRoots::PROD_ONLY);

    $this->assertArrayHasKey('insideonly', $map['class']);
    $this->assertMapStaysInside($map, $package);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
  }

  public function testHhSymlinkRootStaysInsidePackage(): void {
    $package = $this->work.'/pkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($package.'/src/InsideOnly.php', 'InsideOnly');
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    \symlink($outside, $package.'/linked');
    $this->writeHhConfig(
      $package,
      ImmVector { 'src', 'linked' },
      ImmVector { },
    );

    $map = $this->hhMap($package, IncludedRoots::PROD_ONLY);

    $this->assertArrayHasKey('insideonly', $map['class']);
    $this->assertMapStaysInside($map, $package);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
  }

  public function testHhDevRootStaysInsidePackage(): void {
    $package = $this->work.'/pkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($package.'/src/InsideOnly.php', 'InsideOnly');
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    $this->writeHhConfig(
      $package,
      ImmVector { 'src' },
      ImmVector { $outside },
    );

    $map = $this->hhMap($package, IncludedRoots::DEV_AND_PROD);

    $this->assertArrayHasKey('insideonly', $map['class']);
    $this->assertMapStaysInside($map, $package);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
  }

  public function testHhUnreadableDirectoryDoesNotAbort(): void {
    $package = $this->work.'/pkg';
    $root = $package.'/ok';
    $hidden = $root.'/nope';
    $this->writeHackClass($root.'/Ok.php', 'OkClass');
    $this->writeHackClass($hidden.'/Secret.php', 'Secret');
    \chmod($hidden, 0);
    $this->writeHhConfig($package, ImmVector { 'ok' }, ImmVector { });

    $map = $this->hhMap($package, IncludedRoots::PROD_ONLY);

    $this->assertArrayHasKey('okclass', $map['class']);
    $this->assertArrayNotHasKey('secret', $map['class']);
    $this->assertMapStaysInside($map, $package);
  }

  public function testHhUnreadableAbsoluteRootDoesNotAbort(): void {
    $package = $this->work.'/pkg';
    $locked = $this->work.'/locked';
    $this->writeHackClass($package.'/src/InsideOnly.php', 'InsideOnly');
    $this->writeHackClass($locked.'/Locked.php', 'Locked');
    \chmod($locked, 0);
    $this->writeHhConfig(
      $package,
      ImmVector { 'src', $locked },
      ImmVector { },
    );

    $map = $this->hhMap($package, IncludedRoots::PROD_ONLY);

    $this->assertArrayHasKey('insideonly', $map['class']);
    $this->assertArrayNotHasKey('locked', $map['class']);
    $this->assertMapStaysInside($map, $package);
  }

  public function testHhScanRootIsCapped(): void {
    $package = $this->work.'/pkg';
    $this->writeNumberedClasses($package.'/src', self::MAX_SCANNED_FILES + 1);
    $this->writeHhConfig($package, ImmVector { 'src' }, ImmVector { });

    $map = $this->hhMap($package, IncludedRoots::PROD_ONLY);

    $this->assertGreaterThan(0, \count($map['class']));
    $this->assertLessThanOrEqual(
      self::MAX_SCANNED_FILES,
      \count($map['class']),
    );
  }

  public function testComposerClassmapParentSegmentsStayInsidePackage(): void {
    $package = $this->work.'/pkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($package.'/src/LocalClass.php', 'LocalClass');
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    $this->writeComposer(
      $package,
      array(
        'classmap' => array('src', 'src/../../outside'),
      ),
    );
    $before = $this->filesUnder($outside);

    $map = $this->composerMap($package);

    $this->assertSame($before, $this->filesUnder($outside));
    $this->assertArrayHasKey('localclass', $map['class']);
    $this->assertMapStaysInside($map, $package);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
  }

  public function testComposerPsr4ParentSegmentsStayInsidePackage(): void {
    $package = $this->work.'/pkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass(
      $package.'/src/Local.php',
      'Local',
      'Safe',
    );
    $this->writeHackClass(
      $outside.'/Payload.php',
      'Payload',
      'Evil',
    );
    $this->writeComposer(
      $package,
      array(
        'psr-4' => array(
          'Safe\\' => 'src/',
          'Evil\\' => 'src/../../outside/',
        ),
      ),
    );

    $map = $this->composerMap($package);

    $this->assertArrayHasKey('safe\\local', $map['class']);
    $this->assertMapStaysInside($map, $package);
    $this->assertArrayNotHasKey('evil\\payload', $map['class']);
  }

  public function testComposerPsr0ParentSegmentsStayInsidePackage(): void {
    $package = $this->work.'/pkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($package.'/src/LocalPsr0.php', 'LocalPsr0');
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    $this->writeComposer(
      $package,
      array(
        'psr-0' => array(
          'LocalPsr0' => 'src',
          'OutsideOnly' => 'src/../../outside',
        ),
      ),
    );

    $map = $this->composerMap($package);

    $this->assertArrayHasKey('localpsr0', $map['class']);
    $this->assertMapStaysInside($map, $package);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
  }

  public function testComposerAbsoluteClassmapDoesNotAbort(): void {
    $package = $this->work.'/pkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($package.'/src/LocalClass.php', 'LocalClass');
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    $this->writeComposer(
      $package,
      array(
        'classmap' => array('src', $outside),
      ),
    );
    $before = $this->filesUnder($outside);

    $map = $this->composerMap($package);

    $this->assertSame($before, $this->filesUnder($outside));
    $this->assertArrayHasKey('localclass', $map['class']);
    $this->assertMapStaysInside($map, $package);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
  }

  public function testComposerClassmapIsCapped(): void {
    $package = $this->work.'/pkg';
    $this->writeNumberedClasses($package.'/src', self::MAX_SCANNED_FILES + 1);
    $this->writeComposer(
      $package,
      array(
        'classmap' => array('src'),
      ),
    );

    $map = $this->composerMap($package);

    $this->assertGreaterThan(0, \count($map['class']));
    $this->assertLessThanOrEqual(
      self::MAX_SCANNED_FILES,
      \count($map['class']),
    );
  }

  public function testRootImporterVendorHhAbsoluteRootStaysInsidePackage(
  ): void {
    $project = $this->work.'/project';
    $package = $project.'/vendor/evil/hhpkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($project.'/src/ProjInside.php', 'ProjInside');
    $this->writeHhConfig($project, ImmVector { 'src' }, ImmVector { });
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    $this->writeHhConfig($package, ImmVector { $outside }, ImmVector { });
    $before = $this->filesUnder($outside);

    $map = (new RootImporter($project, IncludedRoots::PROD_ONLY))
      ->getAutoloadMap();

    $this->assertSame($before, $this->filesUnder($outside));
    $this->assertArrayHasKey('projinside', $map['class']);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
    $this->assertMapStaysInside($map, $project);
  }

  public function testRootImporterVendorClassmapParentSegmentsStayInsidePackage(
  ): void {
    $project = $this->work.'/project';
    $package = $project.'/vendor/evil/cpkg';
    $outside = $this->work.'/outside';
    $this->writeHackClass($project.'/src/ProjInside.php', 'ProjInside');
    $this->writeHhConfig($project, ImmVector { 'src' }, ImmVector { });
    $this->writeHackClass($package.'/src/LocalClass.php', 'LocalClass');
    $this->writeHackClass($outside.'/OutsideOnly.php', 'OutsideOnly');
    // vendor/evil/cpkg -> vendor/evil -> vendor -> project -> work
    $this->writeComposer(
      $package,
      array(
        'classmap' => array('src', '../../../../outside'),
      ),
    );

    $map = (new RootImporter($project, IncludedRoots::PROD_ONLY))
      ->getAutoloadMap();

    $this->assertArrayHasKey('projinside', $map['class']);
    $this->assertArrayHasKey('localclass', $map['class']);
    $this->assertArrayNotHasKey('outsideonly', $map['class']);
    $outside_real = \realpath($outside);
    $this->assertTrue(\is_string($outside_real));
    foreach ($map['class'] as $file) {
      $real = \realpath($file);
      $this->assertTrue(\is_string($real));
      $this->assertFalse($this->pathIsInside($real, $outside_real));
    }
  }

  private function hhMap(
    string $package,
    IncludedRoots $included,
  ): AutoloadMap {
    return (new HHImporter($package, $included))->getAutoloadMap();
  }

  private function composerMap(string $package): AutoloadMap {
    return (new ComposerImporter($package.'/composer.json', $this->config()))
      ->getAutoloadMap();
  }

  private function config(): Config {
    return shape(
      'autoloadFilesBehavior' => AutoloadFilesBehavior::EXEC_FILES,
      'includeVendor' => true,
      'extraFiles' => ImmVector { },
      'roots' => ImmVector { },
      'devRoots' => ImmVector { },
      'parser' => Parser::EXT_FACTPARSE,
      'relativeAutoloadRoot' => true,
      'failureHandler' => null,
      'devFailureHandler' => null,
    );
  }

  private function writeHhConfig(
    string $package,
    ImmVector<string> $roots,
    ImmVector<string> $dev_roots,
  ): void {
    $this->ensureDir($package);
    \file_put_contents(
      $package.'/hh_autoload.json',
      \json_encode(array(
        'roots' => $roots->toArray(),
        'devRoots' => $dev_roots->toArray(),
        'includeVendor' => true,
      ))."\n",
    );
  }

  private function writeComposer(
    string $package,
    array<string, mixed> $autoload,
  ): void {
    $this->ensureDir($package);
    \file_put_contents(
      $package.'/composer.json',
      \json_encode(array('autoload' => $autoload))."\n",
    );
  }

  private function writeHackClass(
    string $path,
    string $class_name,
    ?string $namespace = null,
  ): void {
    $this->ensureDir(\dirname($path));
    $namespace_line = $namespace === null ? '' : 'namespace '.$namespace.";\n";
    \file_put_contents(
      $path,
      "<?hh\n".$namespace_line.'class '.$class_name." {}\n",
    );
  }

  private function writeXhpClass(
    string $path,
    string $vendor,
    string $name,
  ): void {
    $this->ensureDir(\dirname($path));
    \file_put_contents(
      $path,
      "<?hh\nclass :".$vendor.':'.$name." {}\n",
    );
  }

  private function writeNumberedClasses(string $dir, int $count): void {
    $this->ensureDir($dir);
    for ($i = 0; $i < $count; $i++) {
      \file_put_contents($dir.'/C'.$i.'.php', "<?hh\nclass Cap".$i." {}\n");
    }
  }

  private function assertMapStaysInside(
    AutoloadMap $map,
    string $package,
  ): void {
    $package_real = \realpath($package);
    $this->assertTrue(\is_string($package_real));
    foreach ($map['class'] as $class_name => $file) {
      $real = \realpath($file);
      $this->assertTrue(
        \is_string($real),
        $class_name.' came from '.$file,
      );
      $this->assertTrue(
        $this->pathIsInside($real, $package_real),
        $class_name.' resolved to '.$real,
      );
    }
  }

  private function pathIsInside(string $path, string $directory): bool {
    return $path === $directory || \strpos($path, $directory.'/') === 0;
  }

  private function filesUnder(string $dir): array<string> {
    $files = array();
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(
        $dir,
        \FilesystemIterator::SKIP_DOTS,
      ),
    );
    foreach ($iterator as $info) {
      if ($info->isFile()) {
        $files[] = $info->getPathname();
      }
    }
    \sort(&$files);
    return $files;
  }

  private function makeReadable(string $dir): void {
    if (\is_link($dir)) {
      return;
    }
    if (\is_dir($dir)) {
      \chmod($dir, 0755);
      foreach (\scandir($dir) as $name) {
        if ($name === '.' || $name === '..') {
          continue;
        }
        $this->makeReadable($dir.'/'.$name);
      }
      return;
    }
    \chmod($dir, 0644);
  }

  private function ensureDir(string $dir): void {
    if (!\is_dir($dir)) {
      \mkdir($dir, 0700, true);
    }
  }

  private function removeTree(string $dir): void {
    if (!\file_exists($dir)) {
      return;
    }
    $this->makeReadable($dir);
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(
        $dir,
        \FilesystemIterator::SKIP_DOTS,
      ),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $info) {
      $path = $info->getPathname();
      if (!$info->isLink() && $info->isDir()) {
        \chmod($path, 0755);
        \rmdir($path);
      } else {
        if (!$info->isLink()) {
          \chmod($path, 0644);
        }
        \unlink($path);
      }
    }
    \chmod($dir, 0755);
    \rmdir($dir);
  }
}
