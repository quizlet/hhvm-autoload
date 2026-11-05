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

/** Class for merging multiple autoload maps.
 *
 * For example, we may merge:
 * - the root autoload map
 * - additional autoload maps for each vendored dependency
 * - in the case of composer, a psr0, psr4, and classmap
 */
abstract final class Merger {
  /** Return a new map containing all the entries from the input maps.
   *
   * In the case of duplicates, the last definition is used; this must only be
   * used for maps that belong to the same package. Use `mergePackages()` to
   * combine maps from different packages.
   */
  public static function merge(
    \ConstVector<AutoloadMap> $maps,
  ): AutoloadMap {
    return shape(
      'class' =>
        self::mergeImpl($maps->map($map ==> $map['class'])),
      'function' =>
        self::mergeImpl($maps->map($map ==> $map['function'])),
      'type' =>
        self::mergeImpl($maps->map($map ==> $map['type'])),
      'constant' =>
        self::mergeImpl($maps->map($map ==> $map['constant'])),
    );
  }

  /** Return a new map containing all the entries from several packages.
   *
   * Unlike `merge()`, a definition may only come from one package: if two
   * packages define the same class, type, function, or constant in different
   * files, an `Exception` is thrown instead of letting one replace the other.
   *
   * Classes and types are checked as a single namespace, as HHVM consults
   * both maps when resolving a name.
   *
   * `definitions` must contain every definition in every file that the
   * package's map points to or that is required unconditionally: loading a
   * file defines everything in it, not only the entries the map kept. These
   * are checked for conflicts, but are not added to the map.
   */
  public static function mergePackages(
    \ConstVector<shape(
      'name' => string,
      'map' => AutoloadMap,
      'definitions' => AutoloadMap,
    )> $packages,
  ): AutoloadMap {
    $owners = Map { };
    foreach ($packages as $package) {
      self::claimDefinitions($owners, $package['name'], $package['map']);
      self::claimDefinitions(
        $owners,
        $package['name'],
        $package['definitions'],
      );
    }

    $out = shape(
      'class' => [],
      'function' => [],
      'type' => [],
      'constant' => [],
    );
    foreach ($packages as $package) {
      $map = $package['map'];
      $out['class'] = self::addMissing($out['class'], $map['class']);
      $out['function'] = self::addMissing($out['function'], $map['function']);
      $out['type'] = self::addMissing($out['type'], $map['type']);
      $out['constant'] = self::addMissing($out['constant'], $map['constant']);
    }
    return $out;
  }

  private static function claimDefinitions(
    Map<string, (string, string)> $owners,
    string $package,
    AutoloadMap $map,
  ): void {
    $kinds = ImmMap {
      'class' => $map['class'],
      'type' => $map['type'],
      'function' => $map['function'],
      'constant' => $map['constant'],
    };
    foreach ($kinds as $kind => $definitions) {
      foreach ($definitions as $name => $file) {
        switch ($kind) {
          case 'class':
          case 'type':
            $key = 'class!'.\strtolower($name);
            break;
          case 'function':
            $key = 'function!'.\strtolower($name);
            break;
          default:
            $key = 'constant!'.$name;
            break;
        }
        $path = \realpath($file);
        if ($path === false) {
          $path = $file;
        }

        $owner = $owners->get($key);
        if ($owner === null) {
          $owners[$key] = tuple($package, $path);
          continue;
        }
        list($owner_package, $owner_path) = $owner;
        if ($owner_package === $package || $owner_path === $path) {
          continue;
        }
        throw new Exception(
          "%s '%s' is defined by both %s (in %s) and %s (in %s); refusing to ".
          "let one package replace another package's definition",
          $kind,
          $name,
          $owner_package,
          $owner_path,
          $package,
          $path,
        );
      }
    }
  }

  private static function addMissing(
    array<string, string> $out,
    array<string, string> $map,
  ): array<string, string> {
    foreach ($map as $def => $file) {
      if (!\array_key_exists($def, $out)) {
        $out[$def] = $file;
      }
    }
    return $out;
  }

  private static function mergeImpl(
    Iterable<array<string, string>> $maps,
  ): array<string, string> {
    $out = [];
    foreach ($maps as $map) {
      foreach ($map as $def => $file) {
        $out[$def] = $file;
      }
    }
    return $out;
  }
}
