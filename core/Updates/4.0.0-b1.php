<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Updates;

use Piwik\DataAccess\TableMetadata;
use Piwik\Date;
use Piwik\DbHelper;
use Piwik\Plugin\Manager;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Common;
use Piwik\Config;
use Piwik\Plugins\UserCountry\LocationProvider;
use Piwik\Updater;
use Piwik\Updates as PiwikUpdates;
use Piwik\Updater\Migration\Factory as MigrationFactory;

/**
 * Update for version 4.0.0-b1.
 */
class Updates_4_0_0_b1 extends PiwikUpdates
{
    /**
     * @var MigrationFactory
     */
    private $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function getMigrations(Updater $updater)
    {
        $migrations = [];
        $migrations[] = $this->migration->db->changeColumnType('log_action', 'name', 'VARCHAR(4096)');
        $migrations[] = $this->migration->db->changeColumnType('log_conversion', 'url', 'VARCHAR(4096)');
        $migrations[] = $this->migration->db->dropColumn('log_visit', 'config_gears');
        $migrations[] = $this->migration->db->dropColumn('log_visit', 'config_director');
        $migrations[] = $this->migration->db->changeColumn('log_link_visit_action', 'interaction_position', 'pageview_position', 'MEDIUMINT UNSIGNED DEFAULT NULL');

        /** APP SPECIFIC TOKEN START */
        $migrations[] = $this->migration->db->createTable('user_token_auth', array(
            'idusertokenauth' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'login' => 'VARCHAR(100) NOT NULL',
            'description' => 'VARCHAR('.Model::MAX_LENGTH_TOKEN_DESCRIPTION.') NOT NULL',
            'password' => 'VARCHAR(191) NOT NULL',
            'system_token' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'hash_algo' => 'VARCHAR(30) NOT NULL',
            'last_used' => 'DATETIME NULL',
            'date_created' => ' DATETIME NOT NULL',
            'date_expired' => ' DATETIME NULL',
        ), 'idusertokenauth');
        $migrations[] = $this->migration->db->addUniqueKey('user_token_auth', 'password', 'uniq_password');

        $migrations[] = $this->migration->db->dropIndex('user', 'uniq_keytoken');

        $userModel = new Model();
        foreach ($userModel->getUsers(array()) as $user) {
            if (!empty($user['token_auth'])) {
                $migrations[] = $this->migration->db->insert('user_token_auth', array(
                    'login' => $user['login'],
                    'description' => 'Created by Matomo 4 migration',
                    'password' => $userModel->hashTokenAuth($user['token_auth']),
                    'date_created' => Date::now()->getDatetime()
                ));
            }
        }

        $migrations[] = $this->migration->db->dropColumn('user', 'alias');
        $migrations[] = $this->migration->db->dropColumn('user', 'token_auth');

        /** APP SPECIFIC TOKEN END */

        $customTrackerPluginActive = false;
        if (in_array('CustomPiwikJs', Config::getInstance()->Plugins['Plugins'])) {
            $customTrackerPluginActive = true;
        }

        $migrations[] = $this->migration->plugin->activate('BulkTracking');
        $migrations[] = $this->migration->plugin->deactivate('CustomPiwikJs');
        $migrations[] = $this->migration->plugin->uninstall('CustomPiwikJs');

        if ($customTrackerPluginActive) {
            $migrations[] = $this->migration->plugin->activate('CustomJsTracker');
        }

        // Prepare all installed tables for utf8mb4 conversions. e.g. make some indexed fields smaller so they don't exceed the maximum key length
        $allTables = DbHelper::getTablesInstalled();

        $migrations[] = $this->migration->db->changeColumnType('session', 'id', 'VARCHAR(191)');
        $migrations[] = $this->migration->db->changeColumnType('site_url', 'url', 'VARCHAR(190)');
        $migrations[] = $this->migration->db->changeColumnType('option', 'option_name', 'VARCHAR(191)');

        foreach ($allTables as $table) {
            if (preg_match('/archive_/', $table) == 1) {
                $tableNameUnprefixed = Common::unprefixTable($table);
                $migrations[] = $this->migration->db->changeColumnType($tableNameUnprefixed, 'name', 'VARCHAR(190)');
            }
        }

        // Move the site search fields of log_visit out of custom variables into their own fields
        $migrations[] = $this->migration->db->addColumn('log_link_visit_action', 'search_cat', 'VARCHAR(200) NULL');
        $migrations[] = $this->migration->db->addColumn('log_link_visit_action', 'search_count', 'INTEGER(10) UNSIGNED NULL');

        if (Manager::getInstance()->isPluginInstalled('CustomDimensions')) {
            $visitActionTable = Common::prefixTable('log_link_visit_action');
            $migrations[]     = $this->migration->db->sql("UPDATE $visitActionTable SET search_cat = custom_var_v4 WHERE custom_var_k4 = '_pk_scat'");
            $migrations[]     = $this->migration->db->sql("UPDATE $visitActionTable SET search_count = custom_var_v5 WHERE custom_var_k5 = '_pk_scount'");
        }

        if ($this->usesGeoIpLegacyLocationProvider()) {
            // activate GeoIp2 plugin for users still using GeoIp2 Legacy (others might have it disabled on purpose)
            $migrations[] = $this->migration->plugin->activate('GeoIp2');
        }

        // remove old options
        $migrations[] = $this->migration->db->sql('DELETE FROM `' . Common::prefixTable('option') . '` WHERE option_name IN ("geoip.updater_period", "geoip.loc_db_url", "geoip.isp_db_url", "geoip.org_db_url")');

        $columnsToMaybeAdd = ['revenue', 'revenue_discount', 'revenue_shipping', 'revenue_subtotal', 'revenue_tax'];
        $tableMeta = new TableMetadata();
        $columnsLogConversion = $tableMeta->getColumns(Common::prefixTable('log_conversion'));
        $conversionColumnsToAdd = array();
        foreach ($columnsToMaybeAdd as $columnToMaybeAdd) {
            if (!in_array($columnToMaybeAdd, $columnsLogConversion, true)) {
                $conversionColumnsToAdd[$columnToMaybeAdd] = 'DOUBLE NULL DEFAULT NULL';
            }
        }
        if (!empty($conversionColumnsToAdd)) {
            $migrations[] = $this->migration->db->addColumns('log_conversion', $conversionColumnsToAdd);
        }

        $config = Config::getInstance();

        if (!empty($config->mail['type']) && $config->mail['type'] === 'Crammd5') {
            $migrations[] = $this->migration->config->set('mail', 'type', 'Cram-md5');
        }

        return $migrations;
    }

    public function doUpdate(Updater $updater)
    {
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));

        if ($this->usesGeoIpLegacyLocationProvider()) {
            // switch to default provider if GeoIp Legacy was still in use
            LocationProvider::setCurrentProvider(LocationProvider\DefaultProvider::ID);
        }
    }

    protected function usesGeoIpLegacyLocationProvider()
    {
        $currentProvider = LocationProvider::getCurrentProviderId();

        return in_array($currentProvider, [
            'geoip_pecl',
            'geoip_php',
            'geoip_serverbased',
        ]);
    }
}