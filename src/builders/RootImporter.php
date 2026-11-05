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

/** Build an autoload map for the project root.
 *
 * This will:
 * - create an `HHImporter` for the current directory
 * - create `ComposerImporter`s or `HHImporter`s for every project under
 *   `vendor/`
 *
 * If two of these projects define the same class, function, type, or
 * constant in different files, an `Exception` is thrown.
 */
final class RootImporter implements Builder {
  /** Builders keyed by the directory of the package they belong to */
  private Map<string, Builder> $builders = Map { };
  private HHImporter $hh_importer;

  public function __construct(
    string $root,
    IncludedRoots $included = IncludedRoots::PROD_ONLY,
  ) {
    $this->hh_importer = new HHImporter($root, $included);
    $this->builders[$root] = $this->hh_importer;
    $config = $this->hh_importer->getConfig();

    if (!$config['includeVendor']) {
      return;
    }

    foreach (\glob($root.'/vendor/*/*/') as $dependency) {
      $name = \rtrim($dependency, '/');
      if (\file_exists($dependency.'/hh_autoload.json')) {
        $this->builders[$name] = new HHImporter(
          $dependency,
          IncludedRoots::PROD_ONLY,
        );
        continue;
      }
      $composer_json = $dependency.'/composer.json';
      if (\file_exists($composer_json)) {
        $this->builders[$name] = new ComposerImporter($composer_json, $config);
        continue;
      }
    }
  }

  public function getAutoloadMap(): AutoloadMap {
    $parser = $this->getConfig()['parser'];
    $packages = Vector { };
    foreach ($this->builders as $name => $builder) {
      $map = $builder->getAutoloadMap();
      $files = Set { };
      $files->addAll($map['class']);
      $files->addAll($map['function']);
      $files->addAll($map['type']);
      $files->addAll($map['constant']);
      $files->addAll($builder->getFiles());
      $packages[] = shape(
        'name' => $name,
        'map' => $map,
        'definitions' => Scanner::fromFiles(
          $files->filter($file ==> \is_file($file))->toImmVector(),
          $parser,
        )->getAutoloadMap(),
      );
    }
    return Merger::mergePackages($packages);
  }

  public function getFiles(): ImmVector<string> {
    $files = Vector { };
    foreach ($this->builders as $builder) {
      $files->addAll($builder->getFiles());
    }
    return $files->toImmVector();
  }

  public function getConfig(): Config {
    return $this->hh_importer->getConfig();
  }
}
