<?php


namespace App\Service\Import;


use App\Entity\Governor;
use App\Entity\GovernorSnapshot;
use App\Entity\GovernorStatus;
use App\Entity\Import;
use App\Entity\User;
use App\Exception\APIException;
use App\Exception\GovDataException;
use App\Exception\ImportException;
use App\Exception\NotFoundException;
use App\Repository\AllianceRepository;
use App\Repository\GovernorRepository;
use App\Repository\GovernorSnapshotRepository;
use App\Repository\ImportRepository;
use App\Repository\SnapshotRepository;
use Symfony\Component\Security\Core\User\UserInterface;

class ImportService
{
    private $importRepo;
    private $govRepo;
    private $allianceRepo;
    private $govSnapshotRepo;
    private $snapshotRepo;

    public function __construct(
        ImportRepository $importRepo,
        GovernorRepository $govRepo,
        AllianceRepository $allianceRepo,
        GovernorSnapshotRepository $govSnapshotRepo,
        SnapshotRepository $snapshotRepo
    )
    {
        $this->importRepo = $importRepo;
        $this->govRepo = $govRepo;
        $this->allianceRepo = $allianceRepo;
        $this->govSnapshotRepo = $govSnapshotRepo;
        $this->snapshotRepo = $snapshotRepo;
    }

    /**
     * @param $importId
     * @return Import
     * @throws NotFoundException
     */
    public function getImport($importId): Import
    {
        $import = $this->importRepo->find($importId);
        if (!$import) {
            throw new NotFoundException('import', $importId);
        }

        return $import;
    }

    /**
     * @param string $input
     * @param User|UserInterface $user
     * @return Import
     */
    public function createImport(string $input, User $user): Import
    {
        $import = new Import();
        $import->setStatus(Import::STATUS_CONFIGURING);
        $import->setCreated(new \DateTime);
        $import->setUser($user);
        $import->setInput($input);
        $import->setMappings($this->guessMappings($input));

        return $this->importRepo->save($import);
    }

    public function createPreviewForImport(Import $import): ImportPreview
    {
        $preview = new ImportPreview();
        $preview->snapshot = $import->getSnapshot();

        if (!$import->getMappings()) {
            $import->setMappings($this->guessMappings($import->getInput()));
        }

        if (!$import->getMappings()) {
            $preview->addIssue('Could not guess mappings!');
            return $preview;
        }

        $importMapping = new ImportMapping($import->getMappings());
        $preview->setMapping($importMapping);

        try {
            $rowIndex = 0;
            foreach ($this->processCSV($import->getInput()) as $rowData) {
                ++ $rowIndex;

                if ($rowIndex === 1) {
                    $importMapping->setHeader($rowData);
                    continue;
                }

                try {
                    $preview->addRow(new ImportPreviewRow($rowData, $importMapping));
                } catch (ImportException $e) {
                    $preview->addIssue('Row #' . $rowIndex . ': ' . $e->getMessage());
                }
            }
        } catch (ImportException $e) {
            $preview->addIssue($e->getMessage());
        }

        return $preview;
    }

    public function save(Import $import): Import
    {
        return $this->importRepo->save($import);
    }

    /**
     * @param Import $import
     * @param bool $addNewGovs
     * @return Import
     */
    public function completeImport(Import $import, bool $addNewGovs = true): Import
    {
        $importMapping = new ImportMapping($import->getMappings());
        $rowIndex = 0;
        foreach ($this->processCSV($import->getInput()) as $rowData) {
            ++$rowIndex;

            if ($rowIndex === 1) {
                $importMapping->setHeader($rowData);
                continue;
            }

            try {
                $data = new ImportPreviewRow($rowData, $importMapping);

                $govId = $this->checkGovId($data);
                $gov = $this->govRepo->findOneBy(['governor_id' => $govId]);

                if (!$addNewGovs && !$gov) {
                    continue;
                }

                $this->createOrUpdateGovernor($data);

                if ($import->getSnapshot()) {
                    $data->snapshot = $import->getSnapshot()->getUid();
                }
                $this->addSnapshot($data);
            } catch (ImportException|GovDataException $e) {
            }
        }

        $import->setStatus(Import::STATUS_COMPLETED);
        $import->setCompleted(new \DateTime);

        return $this->importRepo->save($import);
    }

    /**
     * @param string $csvData
     * @return array
     * @throws ImportException
     */
    public function processCSV(string $csvData): array
    {
        try {
            return array_map('str_getcsv', explode("\n", $csvData));
        } catch (\Exception $e) {
            throw new ImportException('Could not parse CSV: ' . $e->getMessage());
        }
    }

    /**
     * @param object $data
     * @return Governor
     * @throws GovDataException
     */
    public function createOrUpdateGovernor(object $data): Governor
    {
        $govId = $this->checkGovId($data);

        $gov = $this->govRepo->findOneBy(['governor_id' => $govId]);
        if (!$gov) {
            $gov = Governor::createFromId($govId);
        }

        $status = $this->getField($data, 'status') ?: GovernorStatus::STATUS_UNKNOWN;
        $gov->setStatus($status);
        $gov->setName($this->getField($data, 'name'));

        $alliance = $this->allianceRepo->findOneBy(['tag' => $data->alliance]);
        if ($alliance) {
            $gov->setAlliance($alliance);
        }

        return $this->govRepo->save($gov);
    }

    /**
     * @param object $data
     * @return GovernorSnapshot
     * @throws GovDataException
     */
    public function addSnapshot(object $data): GovernorSnapshot
    {
        try {
            $govId = $this->checkGovId($data);

            $gov = null;
            $existingGovs = $this->govRepo->findBy(['governor_id' => $govId]);

            if (count($existingGovs) === 0) {
                throw new GovDataException('Could not find gov by id: ' . $govId);
            }
        } catch (GovDataException $e) {
            $govName = $this->getField($data, 'name');
            if ($govName) {
                $existingGovs = $this->govRepo->findBy(['name' => $govName]);
                if (count($existingGovs) === 0) {
                    throw new GovDataException('Could not find gov by name: ' . $govName);
                }
            } else {
                throw $e;
            }
        }

        $gov = $existingGovs[0];

        $snapshot = null;
        $snapshotUid = $this->getField($data, 'snapshot');
        if ($snapshotUid) {
            $snapshot = $this->snapshotRepo->findOneBy(['uid' => $snapshotUid]);
            if (!$snapshot) {
                throw new GovDataException('Could not find snapshot: ' . $snapshotUid);
            }
        }

        $created = isset($data->created) ? new \DateTime($data->created) : new \DateTime();

        $govSnapshot = GovernorSnapshot::fromGov($gov, $created);
        $govSnapshot->setAlliance($this->getField($data, 'alliance'));
        $govSnapshot->setKingdom($this->getField($data, 'kingdom'));
        $govSnapshot->setPower($this->getFieldAsInt($data, 'power'));
        $govSnapshot->setHighestPower($this->getFieldAsInt($data, 'highest_power'));
        $govSnapshot->setKills($this->getFieldAsInt($data, 'kills'));
        $govSnapshot->setT1Kills($this->getFieldAsInt($data, 't1kills'));
        $govSnapshot->setT2Kills($this->getFieldAsInt($data, 't2kills'));
        $govSnapshot->setT3Kills($this->getFieldAsInt($data, 't3kills'));
        $govSnapshot->setT4Kills($this->getFieldAsInt($data, 't4kills'));
        $govSnapshot->setT5Kills($this->getFieldAsInt($data, 't5kills'));
        $govSnapshot->setDeads($this->getFieldAsInt($data, 'deads'));
        $govSnapshot->setHelps($this->getFieldAsInt($data, 'helps'));
        $govSnapshot->setRssGathered($this->getFieldAsInt($data, 'rss_gathered'));
        $govSnapshot->setRssAssistance($this->getFieldAsInt($data, 'rss_assistance'));
        $govSnapshot->setRank($this->getFieldAsInt($data, 'rank'));
        $govSnapshot->setContribution($this->getFieldAsInt($data, 'contribution'));

        if ($snapshot){
            $govSnapshotForUid = $this->govSnapshotRepo->getGovSnapshotForSnapshot($gov, $snapshot);
            if ($govSnapshotForUid) {
                return $this->govSnapshotRepo->save($govSnapshotForUid->merge($govSnapshot));
            } else {
                $govSnapshot->setSnapshot($snapshot);
            }
        }

        return $this->govSnapshotRepo->save($govSnapshot);
    }

    /**
     * @param object $data
     * @return mixed
     * @throws GovDataException
     */
    private function checkGovId(object $data)
    {
        $govId = isset($data->id) ? $data->id : null;
        if (!$govId) {
            throw new GovDataException('Missing gov id!');
        }

        return $govId;
    }

    private function getField(object $data, string $fieldName, $defaultValue = null)
    {
        return isset($data->{$fieldName}) ? $data->{$fieldName} : $defaultValue;
    }

    private function getFieldAsInt(object $data, string $fieldName, $defaultValue = null)
    {
        return $this->sanitizeInt($this->getField($data, $fieldName, $defaultValue));
    }

    private function sanitizeInt($value): int
    {
        return (int) $this->sanitize($value);
    }

    private function sanitize($value)
    {
        return str_replace([',', '.'], '', $value);
    }

    private function guessMappings(string $input): string
    {
        $mappings = [];
        $csv = $this->processCSV($input);
        $header = $csv[0];
        unset($csv);

        foreach (ImportMapping::FIELDS as $field) {
            $foundField = $this->findFieldInHeader($header, $field);

            if (!$foundField) {
                $foundField = $this->findFieldInHeader(
                    $header,
                    str_replace('_', '', $field)
                );
            }

            if ($foundField) {
                $mappings[$field] = $foundField;
            }
        }

        return json_encode($mappings);
    }

    private function findFieldInHeader(array $header, string $field): ?string
    {
        foreach ($header as $headerField) {
            $transformedFields = [
                $headerField,
                \strtoupper($headerField),
                \strtolower($headerField),
                \ucwords($headerField),
                \ucfirst($headerField),
                \str_replace(' ', '', strtolower($headerField))
            ];

            if (in_array($field, $transformedFields)) {
                return $headerField;
            }

            foreach ($this->getAlternativesForField($field) as $fieldAlternative) {
                if (in_array($fieldAlternative, $transformedFields)) {
                    return $headerField;
                }
            }
        }

        return null;
    }

    private function getAlternativesForField(string $field): array
    {
        if ($field === ImportMapping::FIELD_ID) {
            return ['govid', 'governorid', 'rokid', 'playerid'];
        }

        if ($field === ImportMapping::FIELD_NAME) {
            return ['govname', 'governorname', 'playername'];
        }

        if ($field === ImportMapping::FIELD_DEADS) {
            return ['dead'];
        }

        if ($field === ImportMapping::FIELD_RSS_ASSISTANCE) {
            return ['rssdonation', 'rssdonations'];
        }

        return [];
    }
}