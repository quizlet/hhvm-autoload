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

/** Create a `Builder` by scanning file contents.
 */
abstract final class Scanner {
  /** Construct a builder for a single file */
  public static function fromFile(
    string $path,
    Parser $parser,
  ): Builder {
    switch ($parser) {
      case Parser::EXT_FACTPARSE:
        return FactParseScanner::fromFile($path);
    }
  }

  /** Construct a builder for a tree */
  public static function fromTree(
    string $path,
    Parser $parser,
  ): Builder {
    switch ($parser) {
      case Parser::EXT_FACTPARSE:
        return FactParseScanner::fromTree($path);
    }
  }

  /** Resolve a configured autoload path to its canonical location.
   *
   * Returns null if the path does not exist, or if it resolves outside of
   * `$package_root` (for example, via an absolute path, `..` segments, or a
   * symlink); callers must scan the returned path rather than `$path`.
   */
  public static function resolveInsidePackage(
    string $package_root,
    string $path,
  ): ?string {
    $package = \realpath($package_root);
    $resolved = \realpath($path);
    if (!\is_string($package) || !\is_string($resolved)) {
      return null;
    }
    if (self::isPathInside($resolved, $package)) {
      return $resolved;
    }
    \fprintf(
      \STDERR,
      "hhvm-autoload: ignoring %s: it resolves to %s, outside of %s\n",
      $path,
      $resolved,
      $package,
    );
    return null;
  }

  /** Whether canonical path `$path` is `$directory` or below it. */
  public static function isPathInside(
    string $path,
    string $directory,
  ): bool {
    if ($path === $directory) {
      return true;
    }
    return \strpos($path, \rtrim($directory, '/').'/') === 0;
  }
}
