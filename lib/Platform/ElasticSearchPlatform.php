<?php
declare(strict_types=1);


/**
 * FullTextSearch_Elasticsearch - Use Elasticsearch to index the content of your nextcloud
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018
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


namespace OCA\FullTextSearch_Elasticsearch\Platform;


use ArtificialOwl\MySmallPhpTools\Traits\TArrayTools;
use ArtificialOwl\MySmallPhpTools\Traits\TPathTools;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Exception;
use InvalidArgumentException;
use OCA\FullTextSearch_Elasticsearch\Exceptions\AccessIsEmptyException;
use OCA\FullTextSearch_Elasticsearch\Exceptions\ConfigurationException;
use OCA\FullTextSearch_Elasticsearch\Service\ConfigService;
use OCA\FullTextSearch_Elasticsearch\Service\IndexService;
use OCA\FullTextSearch_Elasticsearch\Service\MiscService;
use OCA\FullTextSearch_Elasticsearch\Service\SearchService;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchResult;


/**
 * Class ElasticSearchPlatform
 *
 * @package OCA\FullTextSearch_Elasticsearch\Platform
 */
class ElasticSearchPlatform implements IFullTextSearchPlatform {


	use TPathTools;
	use TArrayTools;


	/** @var ConfigService */
	private $configService;

	/** @var IndexService */
	private $indexService;

	/** @var SearchService */
	private $searchService;

	/** @var MiscService */
	private $miscService;

	/** @var Client */
	private $client;

	/** @var IRunner */
	private $runner;


	/**
	 * ElasticSearchPlatform constructor.
	 *
	 * @param ConfigService $configService
	 * @param IndexService $indexService
	 * @param SearchService $searchService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ConfigService $configService, IndexService $indexService, SearchService $searchService,
		MiscService $miscService
	) {
		$this->configService = $configService;
		$this->indexService = $indexService;
		$this->searchService = $searchService;
		$this->miscService = $miscService;
	}


	/**
	 * return a unique Id of the platform.
	 */
	public function getId(): string {
		return 'elastic_search';
	}


	/**
	 * return a unique Id of the platform.
	 */
	public function getName(): string {
		return 'Elasticsearch';
	}


	/**
	 * @return array
	 * @throws ConfigurationException
	 */
	public function getConfiguration(): array {
		$result = $this->configService->getConfig();

		$sanitizedHosts = [];
		$hosts = $this->configService->getElasticHost();
		foreach ($hosts as $host) {
			$parsedHost = parse_url($host);
			$safeHost = $parsedHost['scheme'] . '://';
			if (array_key_exists('user', $parsedHost)) {
				$safeHost .= $parsedHost['user'] . ':' . '********' . '@';
			}
			$safeHost .= $parsedHost['host'];
			$safeHost .= ':' . $parsedHost['port'];

			$sanitizedHosts[] = $safeHost;
		}

		$result['elastic_host'] = $sanitizedHosts;

		return $result;
	}


	/**
	 * @param IRunner $runner
	 */
	public function setRunner(IRunner $runner) {
		$this->runner = $runner;
	}


	/**
	 * Called when loading the platform.
	 *
	 * Loading some container and connect to ElasticSearch.
	 *
	 * @throws ConfigurationException
	 * @throws Exception
	 */
	public function loadPlatform() {
		try {
			$this->connectToElastic($this->configService->getElasticHost());
		} catch (ConfigurationException $e) {
			throw $e;
		}
	}


	/**
	 * not used yet.
	 *
	 * @return bool
	 */
	public function testPlatform(): bool {
		return $this->client->ping();
	}


	/**
	 * called before any index
	 *
	 * We create a general index.
	 *
	 * @throws ConfigurationException
	 * @throws BadRequest400Exception
	 */
	public function initializeIndex() {
		$this->indexService->initializeIndex($this->client);
	}


	/**
	 * resetIndex();
	 *
	 * Called when admin wants to remove an index specific to a $provider.
	 * $provider can be null, meaning a reset of the whole index.
	 *
	 * @param string $providerId
	 *
	 * @throws ConfigurationException
	 */
	public function resetIndex(string $providerId) {
		if ($providerId === 'all') {
			$this->indexService->resetIndexAll($this->client);
		} else {
			$this->indexService->resetIndex($this->client, $providerId);
		}
	}


	/**
	 * @param IIndexDocument $document
	 *
	 * @return IIndex
	 */
	public function indexDocument(IIndexDocument $document): IIndex {

		$document->initHash();

		$index = null;
		try {
			$result = $this->indexService->indexDocument($this->client, $document);
			$index = $this->indexService->parseIndexResult($document->getIndex(), $result);

			$this->updateNewIndexResult(
				$document->getIndex(), json_encode($result), 'ok',
				IRunner::RESULT_TYPE_SUCCESS
			);

			return $index;
		} catch (Exception $e) {
			$this->manageIndexErrorException($document, $e);
		}

		try {
			$result = $this->indexDocumentError($document, $e);
			$index = $this->indexService->parseIndexResult($document->getIndex(), $result);

			$this->updateNewIndexResult(
				$document->getIndex(), json_encode($result), 'ok',
				IRunner::RESULT_TYPE_WARNING
			);

			return $index;
		} catch (Exception $e) {
			$this->updateNewIndexResult(
				$document->getIndex(), '', 'fail',
				IRunner::RESULT_TYPE_FAIL
			);
			$this->manageIndexErrorException($document, $e);
		}

		return $document->getIndex();
	}


	/**
	 * @param IIndexDocument $document
	 * @param Exception $e
	 *
	 * @return array
	 * @throws AccessIsEmptyException
	 * @throws ConfigurationException
	 * @throws Exception
	 */
	private function indexDocumentError(IIndexDocument $document, Exception $e): array {

		$this->updateRunnerAction('indexDocumentWithoutContent', true);

		$document->setContent('');
//		$index = $document->getIndex();
//		$index->unsetStatus(Index::INDEX_CONTENT);

		return $this->indexService->indexDocument($this->client, $document);
	}


	/**
	 * @param IIndexDocument $document
	 * @param Exception $e
	 */
	private function manageIndexErrorException(IIndexDocument $document, Exception $e) {
		[$level, $message, $status] = $this->parseIndexErrorException($e);
		switch ($level) {
			case 'error':
				$document->getIndex()
						 ->addError($message, get_class($e), IIndex::ERROR_SEV_3);
				$this->updateNewIndexError(
					$document->getIndex(), $message, get_class($e), IIndex::ERROR_SEV_3
				);
				break;

			case 'notice':
				$this->updateNewIndexResult(
					$document->getIndex(),
					$message,
					$status,
					IRunner::RESULT_TYPE_WARNING
				);
				break;
		}

	}


	/**
	 * @param Exception $e
	 *
	 * @return array
	 */
	private function parseIndexErrorException(Exception $e): array {
		$arr = json_decode($e->getMessage(), true);
		if (!is_array($arr)) {
			return ['error', 'unknown error'];
		}

		if (empty($this->getArray('error', $arr))) {
			return ['error', $e->getMessage()];
		}

		try {
			return $this->parseCausedBy($arr['error']);
		} catch (InvalidArgumentException $e) {
		}

		$cause = $this->getArray('error.root_cause', $arr);
		if (!empty($cause) && $this->get('reason', $cause[0]) !== '') {
			return ['error', $this->get('reason', $cause[0]), $this->get('type', $cause[0])];
		}

		return ['error', $e->getMessage()];
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function parseCausedBy(array $error): array {
		$causedBy = $this->getArray('caused_by.caused_by', $error);
		if (empty($causedBy)) {
			$causedBy = $this->getArray('caused_by', $error);
		}

		if (empty($causedBy)) {
			if ($this->get('reason', $error) === '') {
				throw new InvalidArgumentException('Unable to parse given response structure');
			}

			return ['error', $this->get('reason', $error), $this->get('type', $error)];
		}

		$warnings = [
			'encrypted_document_exception',
			'invalid_password_exception'
		];

		$level = 'error';
		if (in_array($this->get('type', $causedBy), $warnings)) {
			$level = 'notice';
		}

		return [$level, $this->get('reason', $causedBy), $this->get('type', $causedBy)];
	}


	/**
	 * {@inheritdoc}
	 */
	public function deleteIndexes(array $indexes) {
		foreach ($indexes as $index) {
			try {
				$this->indexService->deleteIndex($this->client, $index);
				$this->updateNewIndexResult($index, 'index deleted', 'success', IRunner::RESULT_TYPE_SUCCESS);
			} catch (Exception $e) {
				$this->updateNewIndexResult(
					$index, 'index not deleted', 'issue while deleting index', IRunner::RESULT_TYPE_WARNING
				);
			}
		}
	}


	/**
	 * {@inheritdoc}
	 * @throws Exception
	 */
	public function searchRequest(ISearchResult $result, IDocumentAccess $access) {
		$this->searchService->searchRequest($this->client, $result, $access);
	}


	/**
	 * @param string $providerId
	 * @param string $documentId
	 *
	 * @return IIndexDocument
	 * @throws ConfigurationException
	 */
	public function getDocument(string $providerId, string $documentId): IIndexDocument {
		return $this->searchService->getDocument($this->client, $providerId, $documentId);
	}


	private function cleanHost($host) {
		return $this->withoutEndSlash($host, false, false);
	}

	/**
	 * @param array $hosts
	 *
	 * @throws Exception
	 */
	private function connectToElastic(array $hosts) {

		try {
			$hosts = array_map([$this, 'cleanHost'], $hosts);
			$this->client = ClientBuilder::create()
										 ->setHosts($hosts)
										 ->setRetries(3)
										 ->build();

//		}
//		catch (CouldNotConnectToHost $e) {
//			$this 'CouldNotConnectToHost';
//			$previous = $e->getPrevious();
//			if ($previous instanceof MaxRetriesException) {
//				echo "Max retries!";
//			}
		} catch (Exception $e) {
			throw $e;
//			echo ' ElasticSearchPlatform::load() Exception --- ' . $e->getMessage() . "\n";
		}
	}


	/**
	 * @param string $action
	 * @param bool $force
	 *
	 * @throws Exception
	 */
	private function updateRunnerAction(string $action, bool $force = false) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->updateAction($action, $force);
	}


	/**
	 * @param IIndex $index
	 * @param string $message
	 * @param string $exception
	 * @param int $sev
	 */
	private function updateNewIndexError(IIndex $index, string $message, string $exception, int $sev
	) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->newIndexError($index, $message, $exception, $sev);
	}


	/**
	 * @param IIndex $index
	 * @param string $message
	 * @param string $status
	 * @param int $type
	 */
	private function updateNewIndexResult(IIndex $index, string $message, string $status, int $type) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->newIndexResult($index, $message, $status, $type);
	}


}
