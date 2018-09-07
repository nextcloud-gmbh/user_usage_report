<?php
/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserUsageReport\Reports;


use OCA\UserUsageReport\Formatter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\FileInfo;
use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SingleUser {

	use Formatter;

	/** @var IDBConnection */
	protected $connection;

	/** @var IConfig */
	protected $config;

	/** @var IQueryBuilder[] */
	protected $queries = [];

	public function __construct(IDBConnection $connection, IConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param string $userId
	 */
	public function printReport(InputInterface $input, OutputInterface $output, $userId) {
		$this->createQueries();

		$report = array_merge(
			$this->getNumberOfActionsForUser($userId),
			$this->getFilecacheStatsForUser($userId)
		);

		$report['quota'] = $this->getUserQuota($userId);
		$report['shares'] = $this->getNumberOfSharesForUser($userId);
		$report['platform'] = $this->parseUserAgent($userId);

		$this->printRecord($input, $output, $userId, $report);
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	protected function getNumberOfActionsForUser($userId) {
		$query = $this->queries['countActions'];
		$query->setParameter('user', $userId);
		$result = $query->execute();

		$numActions = [
			'uploads' => 0,
			'downloads' => 0,
		];

		while ($row = $result->fetch()) {
			try {
				$metric = $this->actionToMetric($row['action']);
				$numActions[$metric] = (int) $row['num_actions'];
			} catch (\InvalidArgumentException $e) {
				continue;
			}
		}
		$result->closeCursor();

		return $numActions;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	protected function getFilecacheStatsForUser($userId) {
		$query = $this->queries['getStorageId'];

		$home = 'home::' . $userId;
		if (strlen($home) > 64) {
			$home = md5($home);
		}

		$query->setParameter('storage_identifier', $home);
		$result = $query->execute();
		$storageId = (int) $result->fetchColumn();
		$result->closeCursor();

		if ($storageId === 0) {
			$home = 'object::user:' . $userId;
			if (strlen($home) > 64) {
				$home = md5($home);
			}

			$query->setParameter('storage_identifier', $home);
			$result = $query->execute();
			$storageId = (int) $result->fetchColumn();
			$result->closeCursor();
		}

		$query = $this->queries['countFiles'];
		$query->setParameter('storage_identifier', $storageId);
		$result = $query->execute();
		$numFiles = (int) $result->fetchColumn();
		$result->closeCursor();

		$query = $this->queries['getUsedSpace'];
		$query->setParameter('storage_identifier', $storageId);
		$result = $query->execute();
		$usedSpace = (int) $result->fetchColumn();
		$result->closeCursor();

		return [
			'files' => $numFiles,
			'used' => $usedSpace,
		];
	}

	/**
	 * @param string $userId
	 * @return int
	 */
	protected function getUserQuota($userId) {
		$query = $this->queries['getQuota'];
		$query->setParameter('user', $userId);
		$result = $query->execute();
		$quota = $result->fetchColumn();
		$result->closeCursor();

		if (is_numeric($quota)) {
			return (int) $quota;
		}

		if ($quota === 'none') {
			return FileInfo::SPACE_UNLIMITED;
		}

		if ($quota) {
			$quota = \OC_Helper::computerFileSize($quota);
			if ($quota !== false) {
				return (int) $quota;
			}
		}

		return $this->config->getAppValue('files', 'default_quota', FileInfo::SPACE_UNKNOWN);
	}

	/**
	 * @param string $userId
	 * @return int
	 */
	protected function getNumberOfSharesForUser($userId) {
		$query = $this->queries['countShares'];
		$query->setParameter('initiator', $userId);
		$result = $query->execute();
		$numShares = (int) $result->fetchColumn();
		$result->closeCursor();

		return $numShares;
	}

	/**
	 * @param string $userId
	 * @return string
	 */
	protected function parseUserAgent($userId) {
		$query = $this->queries['userAgent'];
		$query->setParameter('uid', $userId);
		$result = $query->execute();
		$userAgent = (string) $result->fetchColumn();
		$result->closeCursor();

		return $this->getPlatformFromUA($userAgent);
	}

	/**
	 * @param string $action
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	protected function actionToMetric($action) {
		switch ($action) {
			case 'created':
				return 'uploads';
			case 'read':
				return 'downloads';
			default:
				throw new \InvalidArgumentException('Unknown action');
		}
	}

	protected function createQueries() {
		if (!empty($this->queries)) {
			return;
		}

		// Get home storage
		$query = $this->connection->getQueryBuilder();
		$query->select('numeric_id')
			->from('storages')
			->where($query->expr()->eq('id', $query->createParameter('storage_identifier')));
		$this->queries['getStorageId'] = $query;

		// Get number of files
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'),'num_files')
			->from('filecache')
			->where($query->expr()->eq('storage', $query->createParameter('storage_identifier')));
		$this->queries['countFiles'] = $query;

		// Get used quota
		$query = $this->connection->getQueryBuilder();
		$query->select('size')
			->from('filecache')
			->where($query->expr()->eq('storage', $query->createParameter('storage_identifier')))
			->andWhere($query->expr()->eq('path_hash', $query->createNamedParameter(md5('files'))));
		$this->queries['getUsedSpace'] = $query;

		// Get quota
		$query = $this->connection->getQueryBuilder();
		$query->select('configvalue')
			->from('preferences')
			->where($query->expr()->eq('userid', $query->createParameter('user')))
			->andWhere($query->expr()->eq('appid', $query->createNamedParameter('files')))
			->andWhere($query->expr()->eq('configkey', $query->createNamedParameter('quota')));
		$this->queries['getQuota'] = $query;

		// Get number of shares
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'),'num_shares')
			->from('share')
			->where($query->expr()->eq('uid_initiator', $query->createParameter('initiator')));
		$this->queries['countShares'] = $query;

		// Get number of downloads and uploads
		$query = $this->connection->getQueryBuilder();
		$query->select(['action'])
			->selectAlias($query->createFunction('COUNT(*)'),'num_actions')
			->from('usage_report')
			->where($query->expr()->eq('user_id', $query->createParameter('user')))
			->groupBy('action');
		$this->queries['countActions'] = $query;

		// Get last active session
		$query = $this->connection->getQueryBuilder();
		$query->select('name')
			->from('authtoken')
			->where($query->expr()->eq('uid', $query->createParameter('uid')))
			->orderBy('last_check', 'DESC')
			->setMaxResults(1);
		$this->queries['userAgent'] = $query;
	}
}
