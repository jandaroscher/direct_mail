<?php

declare(strict_types=1);

namespace DirectMailTeam\DirectMail\Repository;

use TYPO3\CMS\Core\Database\Connection;

class FeUsersRepository extends MainRepository
{
    protected string $table                        = 'fe_users';
    protected string $tablePages                   = 'pages';
    protected string $tableSysDmailGroup           = 'sys_dmail_group';
    protected string $tableSysDmailGroupMm         = 'sys_dmail_group_mm';
    protected string $tableSysDmailGroupCategoryMm = 'sys_dmail_group_category_mm';

    /**
     * @return array|bool
     */
    public function selectFeUsersByUid(int $uid, string $permsClause) //: array|bool
    {
        $queryBuilder = $this->getQueryBuilder($this->table);

        return $queryBuilder
        ->select($this->table . '.*')
        ->from($this->table, $this->table)
        ->leftjoin(
            $this->table,
            $this->tablePages,
            $this->tablePages,
            $queryBuilder->expr()->eq(
                $this->tablePages . '.uid',
                $queryBuilder->quoteIdentifier($this->table . '.pid')
            )
        )
        ->where(
            $queryBuilder->expr()->eq(
                $this->table . '.uid',
                $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
            ),
            $queryBuilder->expr()->eq(
                $this->tablePages . '.deleted',
                $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
            )
        )
        ->andWhere(
            $permsClause
        )
        ->executeQuery()
        ->fetchAllAssociative();
    }

        /**
     * Returns record no matter what - except if record is deleted
     *
     * @param int $uid The uid to look up in $table
     *
     * @return mixed Returns array (the record) if found, otherwise blank/0 (zero)
     * @see getPage_noCheck()
     */
    public function getRawRecord(int $uid) //@TOOD
    {
        if ($uid > 0) {
            $queryBuilder = $this->getQueryBuilder($this->table);
            $queryBuilder->select('*')->from($this->table);
            $queryBuilder->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'deleted',
                    $queryBuilder->createNamedParameter(0)
                )
            );

            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

            if ($rows) {
                if (is_array($rows[0])) {
                    return $rows[0];
                }
            }
        }
        return 0;
    }

     /**
     * Return all uid's from 'fe_users' for a static direct mail group.
     *
     * @param int $uid The uid of the direct_mail group
     *
     * @return array The resulting array of uid's
     */
    public function getStaticIdList(int $uid): array
    {
        $queryBuilder = $this->getQueryBuilder($this->table);

        $res = $queryBuilder
        ->selectLiteral('DISTINCT ' . $this->table . '.uid', $this->table . '.email')
        ->from($this->tableSysDmailGroupMm, $this->tableSysDmailGroupMm)
        ->innerJoin(
            $this->tableSysDmailGroupMm,
            $this->tableSysDmailGroup,
            $this->tableSysDmailGroup,
            $queryBuilder->expr()->eq(
                $this->tableSysDmailGroupMm . '.uid_local',
                $queryBuilder->quoteIdentifier($this->tableSysDmailGroup . '.uid')
            )
        )
        ->innerJoin(
            $this->tableSysDmailGroupMm,
            $this->table,
            $this->table,
            $queryBuilder->expr()->eq(
                $this->tableSysDmailGroupMm . '.uid_foreign',
                $queryBuilder->quoteIdentifier($this->table . '.uid')
            )
        )
        ->andWhere(
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq(
                    $this->tableSysDmailGroupMm . '.uid_local',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    $this->tableSysDmailGroupMm . '.tablenames',
                    $queryBuilder->createNamedParameter($this->table)
                ),
                $queryBuilder->expr()->neq(
                    $this->table . '.email',
                    $queryBuilder->createNamedParameter('')
                ),
                $queryBuilder->expr()->eq(
                    $this->tableSysDmailGroup . '.deleted',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    $this->table . '.module_sys_dmail_newsletter',
                    1
                )
            )
        )
        ->orderBy($this->table . '.uid')
        ->addOrderBy($this->table . '.email')
        ->executeQuery();

        $outArr = [];

        while ($row = $res->fetchAssociative()) {
            $outArr[] = $row['uid'];
        }

        return $outArr;
    }

    /**
     * Return all uid's from fe_users where the $pid is in $pidList.
     * If $cat is 0 or empty, then all entries (with pid $pid) is returned else only
     * entires which are subscribing to the categories of the group with uid $group_uid is returned.
     * The relation between the recipients in fe_users and sys_dmail_categories is a true MM relation
     * (Must be correctly defined in TCA).
     *
     * @param array $pidArray The pidArray
     * @param int $groupUid The groupUid.
     * @param int $cat The number of relations from sys_dmail_group to sysmail_categories
     *
     * @return	array The resulting array of uid's
     */
    public function getIdList(array $pidArray, int $groupUid, int $cat): array
    {
        $queryBuilder = $this->getQueryBuilder($this->table);

        // fe user group uid should be in list of fe users list of user groups
        //		$field = $this->table.'.usergroup';
        //		$command = $this->table.'.uid';
        // This approach, using standard SQL, does not work,
        // even when fe_users.usergroup is defined as varchar(255) instead of tinyblob
        // $usergroupInList = ' AND ('.$field.' LIKE \'%,\'||'.$command.'||\',%\' OR '.$field.' LIKE '.$command.'||\',%\' OR '.$field.' LIKE \'%,\'||'.$command.' OR '.$field.'='.$command.')';
        // The following will work but INSTR and CONCAT are available only in mySQL

        if ($cat < 1) {
            $res = $queryBuilder
            ->selectLiteral('DISTINCT ' . $this->table . '.uid', $this->table . '.email')
            ->from($this->table)
            ->andWhere(
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->in(
                        $this->table . '.pid',
                        $queryBuilder->createNamedParameter($pidArray, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->neq(
                        $this->table . '.email',
                        $queryBuilder->createNamedParameter('')
                    ),
                    $queryBuilder->expr()->eq(
                        $this->table . '.module_sys_dmail_newsletter',
                        1
                    )
                )
            )
            ->orderBy($this->table . '.uid')
            ->addOrderBy($this->table . '.email')
            ->executeQuery();
        } else {
            $mmTable = $GLOBALS['TCA'][$this->table]['columns']['module_sys_dmail_category']['config']['MM'];
            $res = $queryBuilder
            ->selectLiteral('DISTINCT ' . $this->table . '.uid', $this->table . '.email')
            ->from($this->tableSysDmailGroup, $this->tableSysDmailGroup)
            ->from($this->tableSysDmailGroupCategoryMm, 'g_mm')
            ->from($mmTable, 'mm_1')
            ->leftJoin(
                'mm_1',
                $table,
                $table,
                $queryBuilder->expr()->eq(
                    $table . '.uid',
                    $queryBuilder->quoteIdentifier('mm_1.uid_local')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->in(
                        $this->table . '.pid',
                        $queryBuilder->createNamedParameter($pidArray, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->eq(
                        'mm_1.uid_foreign',
                        $queryBuilder->quoteIdentifier('g_mm.uid_foreign')
                    ),
                    $queryBuilder->expr()->eq(
                        $this->tableSysDmailGroup . '.uid',
                        $queryBuilder->quoteIdentifier('g_mm.uid_local')
                    ),
                    $queryBuilder->expr()->eq(
                        $this->tableSysDmailGroup . '.uid',
                        $queryBuilder->createNamedParameter($groupUid, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->neq(
                        $this->table . '.email',
                        $queryBuilder->createNamedParameter('')
                    ),
                    $queryBuilder->expr()->eq(
                        $this->table . '.module_sys_dmail_newsletter',
                        1
                    )
                )
            )
            ->orderBy($this->table . '.uid')
            ->addOrderBy($this->table . '.email')
            ->executeQuery();
        }

        $outArr = [];

        while ($row = $res->fetchAssociative()) {
            $outArr[] = $row['uid'];
        }

        return $outArr;
    }
}
