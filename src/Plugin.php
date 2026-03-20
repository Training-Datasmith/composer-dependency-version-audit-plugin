<?php

/**
 * Copyright © 2021 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
declare (strict_types=1);
namespace Magento\Composer_Dependency_Version_Audit_Plugin;

use Composer\Composer;
use Composer\Dependency_Resolver\Operation\Operation_Interface;
use Composer\Dependency_Resolver\Request;
use Composer\Event_Dispatcher\Event_Subscriber_Interface;
use Composer\Installer;
use Composer\Installer\Package_Event;
use Composer\IO\Io_Interface;
use Composer\Package\Package_Interface;
use Composer\Plugin\Plugin_Events;
use Composer\Plugin\Plugin_Interface;
use Composer\Plugin\Pre_Pool_Create_Event;
use Composer\Repository\Composer_Repository;
use Composer\Repository\Filter_Repository;
use Composer\Repository\Repository_Interface;
use Exception;
use Magento\Composer_Dependency_Version_Audit_Plugin\Utils\Version;
/**
 * Composer's entry point for the plugin
 */
class Plugin implements Plugin_Interface, Event_Subscriber_Interface
{
    /**#@+
     * URL For Public Packagist Repo
     */
    public const URL_REPO_PACKAGIST = 'https://repo.packagist.org';
    /**
     * @var Composer
     */
    private $composer;
    private ?\Magento\Composer_Dependency_Version_Audit_Plugin\Utils\Version $version_selector;
    private ?array $non_fixed_packages = null;
    /**#@+
     * Constant for VBE ALLOW LIST
     */
    private const VBE_ALLOW_LIST = ['vertexinc', 'yotpo', 'klarna', 'amzn', 'dotmailer', 'braintree', 'paypal', 'gene'];
    /**
     * Initialize dependencies
     */
    public function __construct(?Version $version = null)
    {
        if ($version) {
            $this->version_selector = $version;
        } else {
            $this->version_selector = new Version();
        }
    }
    /**
     * @inheritdoc
     */
    public function activate(Composer $composer, Io_Interface $io): void
    {
        // Declaration must exist
    }
    /**
     * @inheritdoc
     */
    public function deactivate(Composer $composer, Io_Interface $io): void
    {
        // Declaration must exist
    }
    /**
     * @inheritdoc
     */
    public function uninstall(Composer $composer, Io_Interface $io): void
    {
        // Declaration must exist
    }
    /**
     * Event subscriber
     */
    public static function get_subscribed_events(): array
    {
        $events = [Installer\Package_Events::PRE_PACKAGE_INSTALL => 'packageUpdate', Installer\Package_Events::PRE_PACKAGE_UPDATE => 'packageUpdate'];
        if ((int) explode('.', Composer::VERSION)[0] === 2) {
            $events[Plugin_Events::PRE_POOL_CREATE] = 'prePoolCreate';
        }
        return $events;
    }
    /**
     * Get all package installations that use non-fixed version constraints (IE: 2.4.*, ^2.4, etc.)
     * this needs to be done for Composer V1 installs since prePoolCreate event doesn't exist in V1
     */
    private function get_non_fixed_constraint_list(Request $request): array
    {
        if (!$this->non_fixed_packages) {
            $constraint_list = [];
            foreach ($request->get_jobs() as $job) {
                if ($job['cmd'] === 'install' && (strpbrk((string) $job['constraint']->get_pretty_string(), '*^-~') || preg_match('{(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)}', (string) $job['constraint']->get_pretty_string()))) {
                    $constraint_list[$job['packageName']] = true;
                }
            }
            $this->non_fixed_packages = $constraint_list;
        }
        return $this->non_fixed_packages;
    }
    /**
     * Event listener for PrePoolCreate event that is used for composer V2
     */
    public function pre_pool_create(Pre_Pool_Create_Event $event): void
    {
        if (!$this->non_fixed_packages) {
            $constraint_list = [];
            /**
             * get all packages that are in the composer.json under require section, this will be the only time
             * we will be able to get constraints for packages in the require section as this request data isn't
             * shared in the installer event on composer v2
             */
            foreach ($event->get_request()->get_requires() as $name => $constraint) {
                $pretty_string = $constraint->get_pretty_string();
                $multi_constraint = preg_match('{(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)}', (string) $pretty_string);
                if (strpbrk((string) $pretty_string, '*^-~') || $multi_constraint) {
                    $constraint_list[$name] = true;
                }
            }
            /**
             * get all sub packages that are now requirements for new packages to install and store their constraints.
             */
            foreach ($event->get_packages() as $package) {
                foreach ($package->get_requires() as $name => $constraint) {
                    $pretty_constraint = $constraint->get_pretty_constraint();
                    $multi_constraint = preg_match('{(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)}', (string) $pretty_constraint);
                    if (strpbrk((string) $pretty_constraint, '*^-~') || $multi_constraint) {
                        $constraint_list[$name] = true;
                    }
                }
            }
            $this->non_fixed_packages = $constraint_list;
        }
    }
    /**
     * Event listener for Package Install or Update
     *
     * @throws Exception
     */
    public function package_update(Package_Event $event): void
    {
        /** @var  OperationInterface */
        $operation = $event->get_operation();
        $this->composer = $event->get_composer();
        /** @var PackageInterface $package  */
        $package = method_exists($operation, 'getPackage') ? $operation->get_package() : $operation->get_initial_package();
        $package_name = $package->get_name();
        $private_repo_version = '';
        $public_repo_version = '';
        $private_repo_url = '';
        $package_name_parts = explode('/', (string) $package_name, 2);
        if (count($package_name_parts) !== 2) {
            return;
        }
        [$namespace, $project] = $package_name_parts;
        $is_package_vbe = in_array($namespace, self::VBE_ALLOW_LIST, true);
        if ((int) explode('.', Composer::VERSION)[0] === 1) {
            $this->get_non_fixed_constraint_list($event->get_request());
        }
        if (!$is_package_vbe) {
            foreach ($this->composer->get_repository_manager()->get_repositories() as $repository) {
                $found = $this->version_selector->find_best_candidate($this->composer, $package_name, $repository);
                $repo_url = '';
                /** @var RepositoryInterface $repository */
                if ($repository instanceof Composer_Repository) {
                    $repo_url = $repository->get_repo_config()['url'];
                } elseif ($repository instanceof Filter_Repository) {
                    $repo_url = $repository->get_repository()->get_repo_config()['url'];
                }
                if ($found) {
                    if ($repo_url && str_contains((string) $repo_url, self::URL_REPO_PACKAGIST)) {
                        $public_repo_version = $found->get_full_pretty_version();
                    } else {
                        $current_private_repo_version = $found->get_full_pretty_version();
                        //private repo version should hold highest version of package
                        if (empty($private_repo_version) || version_compare($current_private_repo_version, $private_repo_version, '>')) {
                            $private_repo_version = $current_private_repo_version;
                            $private_repo_url = $repo_url;
                        }
                    }
                }
            }
            if ($private_repo_version && $public_repo_version && version_compare($public_repo_version, $private_repo_version, '>')) {
                $exception_message = "Higher matching version {$public_repo_version} of {$package_name} was found in public repository packagist.org \n                             than {$private_repo_version} in private {$private_repo_url}. Public package might've been taken over by a malicious entity, \n                             please investigate and update package requirement to match the version from the private repository";
                if ($this->non_fixed_packages && array_key_exists($package_name, $this->non_fixed_packages)) {
                    throw new Exception($exception_message);
                }
                $event->get_io()->write_error('<warning>' . $exception_message . '</warning>');
            }
        }
    }
}