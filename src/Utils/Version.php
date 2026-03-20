<?php

/**
 * Copyright © 2021 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
declare (strict_types=1);
namespace Magento\Composer_Dependency_Version_Audit_Plugin\Utils;

use Composer\Composer;
use Composer\Dependency_Resolver\Pool;
use Composer\Package\Package_Interface;
use Composer\Package\Version\Version_Selector;
use Composer\Repository\Repository_Interface;
use Composer\Repository\Repository_Set;
use Exception;
/**
 * Wrapper class for calling Composer functions
 */
class Version
{
    /**
     * Preferred stability level
     *
     * @var string
     */
    public const STABILITY_DEV = 'dev';
    /**
     * Get Highest version package
     *
     * @throws Exception
     */
    public function find_best_candidate(Composer $composer, string $package_name, Repository_Interface $repository): ?Package_Interface
    {
        $composer_major_version = (int) explode('.', $composer::VERSION)[0];
        if ($composer_major_version === 1) {
            $best_candidate = $this->find_best_candidate_composer1($composer, $package_name, $repository);
        } elseif ($composer_major_version === 2) {
            $best_candidate = $this->find_best_candidate_composer2($composer, $package_name, $repository);
        }
        if ($best_candidate instanceof Package_Interface) {
            return $best_candidate;
        }
        return null;
    }
    /**
     * Get Highest version package for Composer V1
     *
     * @return PackageInterface|false
     */
    public function find_best_candidate_composer1(Composer $composer, string $package_name, Repository_Interface $repository)
    {
        $min_stability = $composer->get_package()->get_minimum_stability();
        $stability_flags = $composer->get_package()->get_stability_flags();
        if (!$min_stability) {
            $min_stability = 'stable';
        }
        $pool = new Pool($min_stability, $stability_flags);
        $pool->add_repository($repository);
        return (new Version_Selector($pool))->find_best_candidate($package_name, null, null, self::STABILITY_DEV);
    }
    /**
     * Get Highest version package for Composer V2
     *
     * @return PackageInterface|false
     */
    public function find_best_candidate_composer2(Composer $composer, string $package_name, Repository_Interface $repository)
    {
        $min_stability = $composer->get_package()->get_minimum_stability();
        $stability_flags = $composer->get_package()->get_stability_flags();
        if (!$min_stability) {
            $min_stability = 'stable';
        }
        $repository_set = new Repository_Set($min_stability, $stability_flags);
        $repository_set->add_repository($repository);
        return (new Version_Selector($repository_set))->find_best_candidate($package_name, null, self::STABILITY_DEV);
    }
}