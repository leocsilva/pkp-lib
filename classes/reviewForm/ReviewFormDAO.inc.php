<?php

/**
 * @file classes/reviewForm/ReviewFormDAO.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewFormDAO
 * @ingroup reviewForm
 *
 * @see ReviewerForm
 *
 * @brief Operations for retrieving and modifying ReviewForm objects.
 *
 */

namespace PKP\reviewForm;

use PKP\db\DAORegistry;
use PKP\db\DAOResultFactory;
use PKP\plugins\HookRegistry;

class ReviewFormDAO extends \PKP\db\DAO
{
    /**
     * Retrieve a review form by ID.
     *
     * @param $reviewFormId int
     * @param $assocType int optional
     * @param $assocId int optional
     *
     * @return ReviewForm
     */
    public function getById($reviewFormId, $assocType = null, $assocId = null)
    {
        $params = [(int) $reviewFormId];
        if ($assocType) {
            $params[] = (int) $assocType;
            $params[] = (int) $assocId;
        }

        $result = $this->retrieve(
            'SELECT	rf.*,
                (SELECT COUNT(*) FROM review_assignments ra WHERE ra.date_completed IS NOT NULL AND ra.declined <> 1 AND ra.review_form_id = rf.review_form_id) AS complete_count,
                (SELECT COUNT(*) FROM review_assignments ra WHERE ra.date_completed IS NULL AND ra.declined <> 1 AND ra.review_form_id = rf.review_form_id) AS incomplete_count
            FROM review_forms rf
            WHERE rf.review_form_id = ? AND rf.assoc_type = ? AND rf.assoc_id = ?',
            $params
        );
        $row = $result->current();
        return $row ? $this->_fromRow((array) $row) : null;
    }

    /**
     * Construct a new data object corresponding to this DAO.
     *
     * @return ReviewForm
     */
    public function newDataObject()
    {
        return new ReviewForm();
    }

    /**
     * Internal function to return a ReviewForm object from a row.
     *
     * @param $row array
     *
     * @return ReviewForm
     */
    public function _fromRow($row)
    {
        $reviewForm = $this->newDataObject();
        $reviewForm->setId($row['review_form_id']);
        $reviewForm->setAssocType($row['assoc_type']);
        $reviewForm->setAssocId($row['assoc_id']);
        $reviewForm->setSequence($row['seq']);
        $reviewForm->setActive($row['is_active']);
        $reviewForm->setCompleteCount($row['complete_count']);
        $reviewForm->setIncompleteCount($row['incomplete_count']);

        $this->getDataObjectSettings('review_form_settings', 'review_form_id', $row['review_form_id'], $reviewForm);

        HookRegistry::call('ReviewFormDAO::_fromRow', [&$reviewForm, &$row]);

        return $reviewForm;
    }

    /**
     * Check if a review form exists with the specified ID.
     *
     * @param $reviewFormId int
     * @param $assocType int
     * @param $assocId int
     *
     * @return boolean
     */
    public function reviewFormExists($reviewFormId, $assocType, $assocId)
    {
        $result = $this->retrieve(
            'SELECT COUNT(*) AS row_count FROM review_forms WHERE review_form_id = ? AND assoc_type = ? AND assoc_id = ?',
            [(int) $reviewFormId, (int) $assocType, (int) $assocId]
        );
        $row = $result->current();
        return $row ? $row->row_count == 1 : false;
    }

    /**
     * Get the list of fields for which data can be localized.
     *
     * @return array
     */
    public function getLocaleFieldNames()
    {
        return ['title', 'description'];
    }

    /**
     * Update the localized fields for this table
     *
     * @param $reviewForm object
     */
    public function updateLocaleFields(&$reviewForm)
    {
        $this->updateDataObjectSettings('review_form_settings', $reviewForm, [
            'review_form_id' => $reviewForm->getId()
        ]);
    }

    /**
     * Insert a new review form.
     *
     * @param $reviewForm ReviewForm
     */
    public function insertObject($reviewForm)
    {
        $this->update(
            'INSERT INTO review_forms
				(assoc_type, assoc_id, seq, is_active)
				VALUES
				(?, ?, ?, ?)',
            [
                (int) $reviewForm->getAssocType(),
                (int) $reviewForm->getAssocId(),
                $reviewForm->getSequence() == null ? 0 : (float) $reviewForm->getSequence(),
                $reviewForm->getActive() ? 1 : 0
            ]
        );

        $reviewForm->setId($this->getInsertId());
        $this->updateLocaleFields($reviewForm);

        return $reviewForm->getId();
    }

    /**
     * Update an existing review form.
     *
     * @param $reviewForm ReviewForm
     */
    public function updateObject($reviewForm)
    {
        $returner = $this->update(
            'UPDATE review_forms
				SET
					assoc_type = ?,
					assoc_id = ?,
					seq = ?,
					is_active = ?
				WHERE review_form_id = ?',
            [
                (int) $reviewForm->getAssocType(),
                (int) $reviewForm->getAssocId(),
                (float) $reviewForm->getSequence(),
                $reviewForm->getActive() ? 1 : 0,
                (int) $reviewForm->getId()
            ]
        );

        $this->updateLocaleFields($reviewForm);

        return $returner;
    }

    /**
     * Delete a review form.
     *
     * @param $reviewForm ReviewForm
     */
    public function deleteObject($reviewForm)
    {
        return $this->deleteById($reviewForm->getId());
    }

    /**
     * Delete a review form by Id.
     *
     * @param $reviewFormId int
     */
    public function deleteById($reviewFormId)
    {
        $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO'); /** @var ReviewFormElementDAO $reviewFormElementDao */
        $reviewFormElementDao->deleteByReviewFormId($reviewFormId);

        $this->update('DELETE FROM review_form_settings WHERE review_form_id = ?', [(int) $reviewFormId]);
        $this->update('DELETE FROM review_forms WHERE review_form_id = ?', [(int) $reviewFormId]);
    }

    /**
     * Delete all review forms by assoc Id.
     *
     * @param $assocType int
     * @param $assocId int
     */
    public function deleteByAssoc($assocType, $assocId)
    {
        $reviewForms = $this->getByAssocId($assocType, $assocId);

        while ($reviewForm = $reviewForms->next()) {
            $this->deleteById($reviewForm->getId());
        }
    }

    /**
     * Get all review forms by assoc id.
     *
     * @param $assocType int
     * @param $assocId int
     * @param $rangeInfo RangeInfo (optional)
     *
     * @return DAOResultFactory containing matching ReviewForms
     */
    public function getByAssocId($assocType, $assocId, $rangeInfo = null)
    {
        $result = $this->retrieveRange(
            'SELECT rf.*,
                (SELECT COUNT(*) FROM review_assignments ra WHERE ra.date_completed IS NOT NULL AND ra.declined <> 1 AND ra.review_form_id = rf.review_form_id) AS complete_count,
                (SELECT COUNT(*) FROM review_assignments ra WHERE ra.date_completed IS NULL AND ra.declined <> 1 AND ra.review_form_id = rf.review_form_id) AS incomplete_count
            FROM	review_forms rf
            WHERE   rf.assoc_type = ? AND rf.assoc_id = ?
            ORDER BY rf.seq',
            [(int) $assocType, (int) $assocId],
            $rangeInfo
        );

        return new DAOResultFactory($result, $this, '_fromRow');
    }

    /**
     * Get active review forms for an associated object.
     *
     * @param $assocType int
     * @param $assocId int
     * @param $rangeInfo object RangeInfo object (optional)
     *
     * @return DAOResultFactory containing matching ReviewForms
     */
    public function getActiveByAssocId($assocType, $assocId, $rangeInfo = null)
    {
        $result = $this->retrieveRange(
            'SELECT rf.*,
                (SELECT COUNT(*) FROM review_assignments ra WHERE ra.date_completed IS NOT NULL AND ra.declined <> 1 AND ra.review_form_id = rf.review_form_id) AS complete_count,
                (SELECT COUNT(*) FROM review_assignments ra WHERE ra.date_completed IS NULL AND ra.declined <> 1 AND ra.review_form_id = rf.review_form_id) AS incomplete_count
                FROM    review_forms rf
                WHERE	rf.assoc_type = ? AND rf.assoc_id = ? AND rf.is_active = 1
                ORDER BY rf.seq',
            [(int) $assocType, (int) $assocId],
            $rangeInfo
        );

        return new DAOResultFactory($result, $this, '_fromRow');
    }

    /**
     * Check if a review form exists with the specified ID.
     *
     * @param $reviewFormId int
     * @param $assocType int optional
     * @param $assocId int optional
     *
     * @return boolean
     */
    public function unusedReviewFormExists($reviewFormId, $assocType = null, $assocId = null)
    {
        $reviewForm = $this->getById($reviewFormId, $assocType, $assocId);
        if (!$reviewForm) {
            return false;
        }
        if ($reviewForm->getCompleteCount() != 0 || $reviewForm->getIncompleteCount() != 0) {
            return false;
        }
        return true;
    }

    /**
     * Sequentially renumber review form in their sequence order.
     *
     * @param $assocType int
     * @param $assocId int
     */
    public function resequenceReviewForms($assocType, $assocId)
    {
        $result = $this->retrieve('SELECT review_form_id FROM review_forms WHERE assoc_type = ? AND assoc_id = ? ORDER BY seq', [(int) $assocType, (int) $assocId]);

        for ($i = 1; $row = $result->current(); $i++) {
            $this->update('UPDATE review_forms SET seq = ? WHERE review_form_id = ?', [$i, $row->review_form_id]);
            $result->next();
        }
    }

    /**
     * Get the ID of the last inserted review form.
     *
     * @return int
     */
    public function getInsertId()
    {
        return $this->_getInsertId('review_forms', 'review_form_id');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\reviewForm\ReviewFormDAO', '\ReviewFormDAO');
}
