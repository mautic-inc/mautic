<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * FrequecyRuleRepository.
 */
class FrequencyRuleRepository extends CommonRepository
{
    /**
     * @param string      $channel
     * @param array       $leadIds
     * @param string|null $defaultFrequencyNumber
     * @param string|null $defaultFrequencyTime
     * @param string      $statTable
     * @param string      $statSentColumn
     * @param string      $statContactColumn
     *
     * @return array
     */
    public function getAppliedFrequencyRules(
        $channel,
        $leadIds,
        $defaultFrequencyNumber,
        $defaultFrequencyTime,
        $statTable = 'email_stats',
        $statContactColumn = 'lead_id',
        $statSentColumn = 'date_sent'
    ) {
        if (empty($leadIds)) {
            return [];
        }

        $frequencyRuleViolations = $this->getCustomFrequencyRuleViolations($channel, $leadIds, $statTable, $statContactColumn, $statSentColumn);

        if (!$this->validateDefaultParameters($defaultFrequencyNumber, $defaultFrequencyTime)) {
            // It makes no sense to calculate default rule violations
            // if default parameters are not valid
            return $frequencyRuleViolations;
        }

        $defaultRuleViolations = $this->getDefaultFrequencyRuleViolations($leadIds, $defaultFrequencyNumber, $defaultFrequencyTime, $statTable, $statContactColumn, $statSentColumn);

        return array_merge($frequencyRuleViolations, $defaultRuleViolations);
    }

    /**
     * Validates default parameters
     *
     * @param mixed $number
     * @param mixed $time
     *
     * @return bool
     */
    private function validateDefaultParameters($number, $time)
    {
        return $number && $time;
    }

    /**
     * @param null $channel
     * @param null $leadIds
     *
     * @return array
     */
    public function getFrequencyRules($channel = null, $leadIds = null)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select(
            'fr.id, fr.frequency_time, fr.frequency_number, fr.channel, fr.preferred_channel, fr.pause_from_date, fr.pause_to_date, fr.lead_id'
        )
            ->from(MAUTIC_TABLE_PREFIX.'lead_frequencyrules', 'fr');

        if ($channel) {
            $q->andWhere('fr.channel = :channel')
                ->setParameter('channel', $channel);
        }

        $groupByLeads = is_array($leadIds);
        if ($leadIds) {
            if ($groupByLeads) {
                $q->andWhere(
                    $q->expr()->in('fr.lead_id', $leadIds)
                );
            } else {
                $q->andWhere('fr.lead_id = :leadId')
                    ->setParameter('leadId', (int) $leadIds);
            }
        }

        $results = $q->execute()->fetchAll();

        $frequencyRules = [];

        foreach ($results as $result) {
            if ($groupByLeads) {
                if (!isset($frequencyRules[$result['lead_id']])) {
                    $frequencyRules[$result['lead_id']] = [];
                }

                $frequencyRules[$result['lead_id']][$result['channel']] = $result;
            } else {
                $frequencyRules[$result['channel']] = $result;
            }
        }

        return $frequencyRules;
    }

    /**
     * @param $leadId
     *
     * @return array
     */
    public function getPreferredChannel($leadId)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('fr.id, fr.frequency_time, fr.frequency_number, fr.channel, fr.pause_from_date, fr.pause_to_date')
            ->from(MAUTIC_TABLE_PREFIX.'lead_frequencyrules', 'fr');
        $q->where('fr.preferred_channel = :preferredChannel')
            ->setParameter('preferredChannel', true, 'boolean');
        if ($leadId) {
            $q->andWhere('fr.lead_id = :leadId')
                ->setParameter('leadId', $leadId);
        }

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * @param string $channel
     * @param array  $leadIds
     * @param string $statTable
     * @param string $statContactColumn
     * @param string $statSentColumn
     *
     * @return array
     */
    private function getCustomFrequencyRuleViolations($channel, array $leadIds, $statTable, $statContactColumn, $statSentColumn)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $q->select("ch.$statContactColumn, fr.frequency_number, fr.frequency_time")
            ->from(MAUTIC_TABLE_PREFIX.$statTable, 'ch')
            ->join('ch', MAUTIC_TABLE_PREFIX.'lead_frequencyrules', 'fr', "ch.{$statContactColumn} = fr.lead_id");

        if ($channel) {
            $q->andWhere('fr.channel = :channel')
                ->setParameter('channel', $channel);
        }

        // Preferred channel is stored in this table so they may not have a frequency rule defined but just a preference so exclude them
        $q->andWhere('fr.frequency_time IS NOT NULL AND fr.frequency_number IS NOT NULL');

        // Calculate the rule timeframe
        $q->andWhere(
            '(ch.'.$statSentColumn.' >= case fr.frequency_time
                 when \'MONTH\' then DATE_SUB(NOW(),INTERVAL 1 MONTH)
                 when \'DAY\' then DATE_SUB(NOW(),INTERVAL 1 DAY)
                 when \'WEEK\' then DATE_SUB(NOW(),INTERVAL 1 WEEK)
                end)'
        );

        $q->andWhere(
            $q->expr()->in("ch.$statContactColumn", $leadIds)
        );

        $q->groupBy("ch.$statContactColumn, fr.frequency_time, fr.frequency_number");

        $q->having("count(ch.$statContactColumn) >= fr.frequency_number");

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * @param array  $leadIds
     * @param string $defaultFrequencyNumber
     * @param string $defaultFrequencyTime
     * @param string $statTable
     * @param string $statContactColumn
     * @param string $statSentColumn
     *
     * @return array
     */
    private function getDefaultFrequencyRuleViolations(
        array $leadIds,
        $defaultFrequencyNumber,
        $defaultFrequencyTime,
        $statTable,
        $statContactColumn,
        $statSentColumn
    ) {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $query->select("ch.$statContactColumn")
            ->from(MAUTIC_TABLE_PREFIX.$statTable, 'ch');

        switch ($defaultFrequencyTime) {
            case 'MONTH':
                $since = new \DateTime('-1 month', new \DateTimeZone('UTC'));
                break;
            case 'WEEK':
                $since = new \DateTime('-1 week', new \DateTimeZone('UTC'));
                break;
            case 'DAY':
                $since = new \DateTime('-1 day', new \DateTimeZone('UTC'));
                break;
            default:
                return [];
        }

        $query->andWhere('ch.'.$statSentColumn.' >= :frequencyTime')
            ->setParameter('frequencyTime', $since->format('Y-m-d H:i:s'));

        $query->andWhere(
            $query->expr()->in("ch.$statContactColumn", $leadIds)
        );

        $hasCustomRules = $this->tableHasRows(MAUTIC_TABLE_PREFIX.'lead_frequencyrules');
        // We don't need to check if users have custom frequency rules if there are no records inside that table
        if ($hasCustomRules) {
            // Exclude contacts with custom rules defined
            $subQuery = $this->getEntityManager()->getConnection()->createQueryBuilder();
            $subQuery->select('null')
                ->from(MAUTIC_TABLE_PREFIX.'lead_frequencyrules', 'fr')
                ->where("fr.lead_id = ch.{$statContactColumn}")
                ->andWhere('fr.frequency_time IS NOT NULL AND fr.frequency_number IS NOT NULL');
            $query->andWhere(
                sprintf('NOT EXISTS (%s)', $subQuery->getSQL())
            );
        }

        $query->groupBy("ch.$statContactColumn");

        $query->having("count(ch.$statContactColumn) >= :defaultNumber")
            ->setParameter('defaultNumber', $defaultFrequencyNumber);

        $results = $query->execute()->fetchAll();
        foreach ($results as $key => $result) {
            $results[$key]['frequency_number'] = $defaultFrequencyNumber;
            $results[$key]['frequency_time']   = $defaultFrequencyTime;
        }

        return $results;
    }
}
