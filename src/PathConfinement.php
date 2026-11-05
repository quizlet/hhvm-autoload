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

abstract final class PathConfinement {
  /** Resolve `$path` and return the result only if it is a file inside
   * `$directory`.
   *
   * Symlinks and `..` components are resolved before the comparison, so the
   * returned canonical path is the file that was checked; callers should use
   * it instead of `$path`.
   */
  public static function resolveFileInside(
    string $directory,
    string $path,
  ): ?string {
    $directory = \realpath($directory);
    $resolved = \realpath($path);
    if ($directory === false || $resolved === false) {
      return null;
    }
    if (\strpos($resolved, \rtrim($directory, '/').'/') !== 0) {
      return null;
    }
    if (!\is_file($resolved)) {
      return null;
    }
    return $resolved;
  }
}
