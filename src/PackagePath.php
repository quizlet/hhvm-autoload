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

/** Resolve paths declared by a package against that package's directory. */
abstract final class PackagePath {
  /** Resolve `$path` to a real path that is inside `$package_root`.
   *
   * Returns null if the path does not exist; throws if it resolves (including
   * through symlinks) to anything outside of `$package_root`. Callers must use
   * the returned path rather than resolving `$path` again.
   */
  public static function resolveInside(
    string $package_root,
    string $path,
  ): ?string {
    $real_root = \realpath($package_root);
    if (!\is_string($real_root)) {
      throw new Exception('%s does not exist', $package_root);
    }
    $real = \realpath($path);
    if (!\is_string($real)) {
      return null;
    }
    if (!self::isInside($real, $real_root)) {
      throw new Exception(
        '%s resolves to %s, which is outside of %s',
        $path,
        $real,
        $real_root,
      );
    }
    return $real;
  }

  /** Whether `$path` is strictly below `$directory`; both must be real paths */
  public static function isInside(string $path, string $directory): bool {
    if ($directory === '/') {
      return $path !== '/' && \substr($path, 0, 1) === '/';
    }
    return \strpos($path, $directory.'/') === 0;
  }
}
