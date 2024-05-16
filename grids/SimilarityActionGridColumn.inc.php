<?php

/**
 * @file plugins/generic/plagiarism/grids/SimilarityActionGridColumn.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SimilarityActionGridColumn
 * @ingroup plugins_generic_plagiarism
 *
 * @brief GridColumn handler to show similarity score and actions related to iThenticate
 */

import('lib.pkp.classes.db.DAORegistry');
import('lib.pkp.classes.controllers.grid.GridColumn');
import('lib.pkp.classes.controllers.grid.ColumnBasedGridCellProvider');
import('lib.pkp.classes.linkAction.request.OpenWindowAction');
import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');
import('lib.pkp.classes.linkAction.request.AjaxModal');

class SimilarityActionGridColumn extends GridColumn {
	
	/**
	 * List of columns record to retrieve to show ithenticate's similarity scores
	 *
	 * @var array
	 */
	protected $similarityScoreColumns = [];

    /** 
	 * The Plagiarism Plugin itself
	 * 
	 * @var PlagiarismPlugin
	 */
	protected $_plugin;

	/**
	 * Constructor
	 *
     * @param PlagiarismPlugin  $plugin         The Plagiarism Plugin itself
	 * @param array             $scoreColumns   List of columns record to retrieve to show ithenticate's 
	 *                                          similarity scores 
	 */
    public function __construct($plugin, $scoreColumns) {

        $this->_plugin = $plugin;
        $this->similarityScoreColumns = $scoreColumns;

        $cellProvider = new ColumnBasedGridCellProvider();

		parent::__construct(
            'score',
            'plugins.generic.plagiarism.similarity.action.column.score.title',
            null,
            null, 
            $cellProvider,
			['width' => 50, 'alignment' => COLUMN_ALIGNMENT_LEFT, 'anyhtml' => true]
        );
	}

	/**
	 * Method expected by ColumnBasedGridCellProvider to render a cell in this column.
	 *
	 * @copydoc ColumnBasedGridCellProvider::getTemplateVarsFromRowColumn()
	 */
	public function getTemplateVarsFromRow($row) {

		$submissionFileData = $row->getData();
		$submissionFile = $submissionFileData['submissionFile']; /** @var SubmissionFile $submissionFile */
		assert($submissionFile instanceof SubmissionFile);

        // submission similarity score is available
        if ($submissionFile->getData('ithenticate_similarity_scheduled') === true &&
            $submissionFile->getData('ithenticate_similarity_result')) {
            
            $similarityResult = json_decode(
                $submissionFile->getData('ithenticate_similarity_result'),
                true
            );
            $scores = '';

            foreach ($similarityResult as $column => $value) {
                if (!in_array($column, $this->similarityScoreColumns)) {
                    continue;
                }

                $scores = $scores . '<li>' . __("plugins.generic.plagiarism.similarity.score.column.{$column}") . ' : ' . $value .'</li>';
            }

            return ['label' => "<ul>{$scores}</ul>"];
        }

        return ['label' => ''];
	}

    /**
	 * @copydoc GridColumn::getCellActions()
	 */
	public function getCellActions($request, $row, $position = GRID_ACTION_POSITION_DEFAULT) {
		$cellActions = parent::getCellActions($request, $row, $position);
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();

        // User can not perform any of following actions if not a Journal Manager or Editor
        //      - Upload file for plagiarims check if failed
        //      - Schedule similarity report generation if not scheduled already
        //      - Refresh the similarity report scores
        //      - Launch similarity report viewer
        if (!$user->hasRole([ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR], $context->getId())) {
			return $cellActions;
		}

		$submissionFileData = $row->getData();
        $submissionFile = $submissionFileData['submissionFile']; /** @var SubmissionFile $submissionFile */
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($submissionFile->getData('submissionId'));

        // There was an error and submission not completed, 
        // Ask for confirmation and try to complete the submission process
        if (!$submissionFile->getData('ithenticate_id')) {

            // first check if curernt user has already EULA confirmed that is associated with submission
            // If not confirmed, need to confirm EULA first before uploading submission to iThenticate

            if ($this->isEulaConfirmationRequired($context, $submission, $user)) {

                $cellActions[] = new LinkAction(
                    "plagiarism-eula-confirmation-{$submissionFile->getId()}",
                    new AjaxModal(
                        $request->getDispatcher()->url(
                            $request,
                            ROUTE_COMPONENT,
                            $context->getData('urlPath'),
                            'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
                            'confirmEula',
                            null,
                            [
                                'submissionFileId' => $submissionFile->getId(),
                                'onAcceptAction' => 'submitSubmission',
                            ]
                        ),
                        __('plugins.generic.plagiarism.similarity.action.confirmEula.title')
                    ),
                    __('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title')
                );
                
                return $cellActions;
            }

            $cellActions[] = new LinkAction(
                "plagiarism-submission-submit-{$submissionFile->getId()}",
                new RemoteActionConfirmationModal(
                    $request->getSession(),
                    __('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.confirmation'),
                    __('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title'),
                    $request->getDispatcher()->url(
                        $request,
                        ROUTE_COMPONENT,
                        $context->getData('urlPath'),
                        'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
                        'submitSubmission',
                        null,
                        [
                            'submissionFileId' => $submissionFile->getId(),
                        ]
                    )
                ),
                __('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title')
            );

            return $cellActions;
        }
        
        // Submission similarity report generation has not scheduled
        if ($submissionFile->getData('ithenticate_similarity_scheduled') === false) {
		    $cellActions[] = new LinkAction(
                "plagiarism-similarity-report-schedule-{$submissionFile->getId()}",
                new RemoteActionConfirmationModal(
                    $request->getSession(),
                    __('plugins.generic.plagiarism.similarity.action.generateReport.confirmation'),
                    __('plugins.generic.plagiarism.similarity.action.generateReport.title'),
                    $request->getDispatcher()->url(
                        $request,
                        ROUTE_COMPONENT,
                        $context->getData('urlPath'),
                        'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
                        'scheduleSimilarityReport',
                        null,
                        [
                            'submissionFileId' => $submissionFile->getId(),
                        ]
                    )
                ),
                __('plugins.generic.plagiarism.similarity.action.generateReport.title')
            );

            return $cellActions;
        }

        // Generate the action for similarity score refresh
        $similarityResultRefreshAction = new LinkAction(
            "plagiarism-similarity-score-refresh-{$submissionFile->getId()}",
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.generic.plagiarism.similarity.action.refreshReport.confirmation'),
                __('plugins.generic.plagiarism.similarity.action.refreshReport.title'),
                $request->getDispatcher()->url(
                    $request,
                    ROUTE_COMPONENT,
                    $context->getData('urlPath'),
                    'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
                    'refreshSimilarityResult',
                    null,
                    [
                        'submissionFileId' => $submissionFile->getId(),
                    ]
                )
            ),
            __('plugins.generic.plagiarism.similarity.action.refreshReport.title')
        );

        // If similarity score not availabel
        // show as cell action and upon it's available, show it as part of row action
        $submissionFile->getData('ithenticate_similarity_result')
            ? $row->addAction($similarityResultRefreshAction)
            : array_push($cellActions, $similarityResultRefreshAction);

        // Similarity viewer only available upon the availability of similarity report is 
        if ($submissionFile->getData('ithenticate_similarity_result')) {
            $row->addAction(
                new LinkAction(
                    "plagiarism-similarity-launch-viewer-{$submissionFile->getId()}",
                    new OpenWindowAction(
                        $request->getDispatcher()->url(
                            $request,
                            ROUTE_COMPONENT,
                            $context->getData('urlPath'),
                            'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
                            'launchViewer',
                            null,
                            [
                                'submissionFileId' => $submissionFile->getId(),
                            ]
                        )
                    ),
                    __('plugins.generic.plagiarism.similarity.action.launch.viewer.title')
                )
            );
        }

		return $cellActions;
	}

    /**
	 * Check for the requrement of EULA confirmation
	 *
     * @param Context       $context
     * @param Submission    $submission
     * @param User          $user
     * 
     * @return bool
	 */
    protected function isEulaConfirmationRequired($context, $submission, $user) {

        // Check if EULA confirmation required for this tenant
        if ($this->_plugin->getContextEulaDetails($context, 'require_eula') === false) {
            return false;
        }

        // If no EULA is stamped with submission
        // means submission never passed through iThenticate process
        if (!$submission->getData('ithenticate_eula_version')) {
            return true;
        }

        // If no EULA is stamped with submission
        // means user has never previously interacted with iThenticate process
        if (!$user->getData('ithenticateEulaVersion')) {
            return true;
        }

        // If user and submission EULA do not match
        // means users previously agreed upon different EULA
        if ($user->getData('ithenticateEulaVersion') !== $submission->getData('ithenticate_eula_version')) {
            return true;
        }

        return false;
    }

}
