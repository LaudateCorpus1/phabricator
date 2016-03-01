<?php

final class PonderQuestionViewController extends PonderController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $id = $request->getURIData('id');

    $question = id(new PonderQuestionQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->needAnswers(true)
      ->needProjectPHIDs(true)
      ->executeOne();
    if (!$question) {
      return new Aphront404Response();
    }

    $answers = $this->buildAnswers($question);

    $answer_add_panel = id(new PonderAddAnswerView())
      ->setQuestion($question)
      ->setUser($viewer)
      ->setActionURI('/ponder/answer/add/');

    $header = new PHUIHeaderView();
    $header->setHeader($question->getTitle());
    $header->setUser($viewer);
    $header->setPolicyObject($question);

    if ($question->getStatus() == PonderQuestionStatus::STATUS_OPEN) {
      $header->setStatus('fa-square-o', 'bluegrey', pht('Open'));
    } else {
      $text = PonderQuestionStatus::getQuestionStatusFullName(
        $question->getStatus());
      $icon = PonderQuestionStatus::getQuestionStatusIcon(
        $question->getStatus());
      $header->setStatus($icon, 'dark', $text);
    }

    $properties = $this->buildPropertyListView($question);
    $actions = $this->buildActionListView($question);
    $details = $this->buildPropertySectionView($question);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $question,
      PhabricatorPolicyCapability::CAN_EDIT);

    $content_id = celerity_generate_unique_node_id();
    $timeline = $this->buildTransactionTimeline(
      $question,
      id(new PonderQuestionTransactionQuery())
      ->withTransactionTypes(array(PhabricatorTransactions::TYPE_COMMENT)));
    $xactions = $timeline->getTransactions();

    $add_comment = id(new PhabricatorApplicationTransactionCommentView())
      ->setUser($viewer)
      ->setObjectPHID($question->getPHID())
      ->setShowPreview(false)
      ->setAction($this->getApplicationURI("/question/comment/{$id}/"))
      ->setSubmitButtonName(pht('Comment'));

    $add_comment = phutil_tag_div(
      'ponder-question-add-comment-view', $add_comment);

    $comment_view = phutil_tag(
      'div',
      array(
        'id' => $content_id,
        'style' => 'display: none;',
      ),
      array(
        $timeline,
        $add_comment,
      ));

    $footer = id(new PonderFooterView())
      ->setContentID($content_id)
      ->setCount(count($xactions));

    $crumbs = $this->buildApplicationCrumbs($this->buildSideNavView());
    $crumbs->addTextCrumb('Q'.$id, '/Q'.$id);
    $crumbs->setBorder(true);

    $subheader = $this->buildSubheaderView($question);

    $answer_wiki = null;
    if ($question->getAnswerWiki()) {
      $wiki = new PHUIRemarkupView($viewer, $question->getAnswerWiki());
      $answer_wiki = id(new PHUIObjectBoxView())
        ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
        ->setHeaderText(pht('ANSWER SUMMARY'))
        ->appendChild($wiki)
        ->addClass('ponder-answer-wiki');
    }

    require_celerity_resource('ponder-view-css');

    $ponder_content = phutil_tag(
      'div',
      array(
        'class'  => 'ponder-question-content',
      ),
      array(
        $footer,
        $comment_view,
        $answer_wiki,
        $answers,
        $answer_add_panel,
      ));

    $ponder_view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setSubheader($subheader)
      ->setMainColumn($ponder_content)
      ->setPropertyList($properties)
      ->addPropertySection(pht('DETAILS'), $details)
      ->setActionList($actions)
      ->addClass('ponder-question-view');

    $page_objects = array_merge(
          array($question->getPHID()),
          mpull($question->getAnswers(), 'getPHID'));

    return $this->newPage()
      ->setTitle('Q'.$question->getID().' '.$question->getTitle())
      ->setCrumbs($crumbs)
      ->setPageObjectPHIDs($page_objects)
      ->appendChild(
        array(
          $ponder_view,
        ));
  }

  private function buildActionListView(PonderQuestion $question) {
    $viewer = $this->getViewer();
    $request = $this->getRequest();
    $id = $question->getID();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $question,
      PhabricatorPolicyCapability::CAN_EDIT);

    $view = id(new PhabricatorActionListView())
      ->setUser($viewer)
      ->setObject($question);

    if ($question->getStatus() == PonderQuestionStatus::STATUS_OPEN) {
      $name = pht('Close Question');
      $icon = 'fa-check-square-o';
    } else {
      $name = pht('Reopen Question');
      $icon = 'fa-square-o';
    }

    $view->addAction(
      id(new PhabricatorActionView())
      ->setIcon('fa-pencil')
      ->setName(pht('Edit Question'))
      ->setHref($this->getApplicationURI("/question/edit/{$id}/"))
      ->setDisabled(!$can_edit)
      ->setWorkflow(!$can_edit));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName($name)
        ->setIcon($icon)
        ->setWorkflow(true)
        ->setDisabled(!$can_edit)
        ->setHref($this->getApplicationURI("/question/status/{$id}/")));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setIcon('fa-list')
        ->setName(pht('View History'))
        ->setHref($this->getApplicationURI("/question/history/{$id}/")));

    return $view;
  }

  private function buildPropertyListView(
    PonderQuestion $question) {

    $viewer = $this->getViewer();
    $view = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($question);

    $view->invokeWillRenderEvent();

    return $view;
  }

  private function buildSubheaderView(
    PonderQuestion $question) {
    $viewer = $this->getViewer();

    $asker = $viewer->renderHandle($question->getAuthorPHID())->render();
    $date = phabricator_datetime($question->getDateCreated(), $viewer);
    $asker = phutil_tag('strong', array(), $asker);

    $author = id(new PhabricatorPeopleQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($question->getAuthorPHID()))
      ->needProfileImage(true)
      ->executeOne();

    $image_uri = $author->getProfileImageURI();
    $image_href = '/p/'.$author->getUsername();

    $content = pht('Asked by %s on %s.', $asker, $date);

    return id(new PHUIHeadThingView())
      ->setImage($image_uri)
      ->setImageHref($image_href)
      ->setContent($content);
  }

  private function buildPropertySectionView(
    PonderQuestion $question) {
    $viewer = $this->getViewer();

    $question_details = PhabricatorMarkupEngine::renderOneObject(
      $question,
      $question->getMarkupField(),
      $viewer);

    if (!$question_details) {
      $question_details = phutil_tag(
        'em',
        array(),
        pht('No further details for this question.'));
    }

    $question_details = phutil_tag_div(
      'phabricator-remarkup ml', $question_details);

    return $question_details;
  }

  /**
   * This is fairly non-standard; building N timelines at once (N = number of
   * answers) is tricky business.
   *
   * TODO - re-factor this to ajax in one answer panel at a time in a more
   * standard fashion. This is necessary to scale this application.
   */
  private function buildAnswers(PonderQuestion $question) {
    $viewer = $this->getViewer();
    $answers = $question->getAnswers();

    if ($answers) {
      $author_phids = mpull($answers, 'getAuthorPHID');
      $handles = $this->loadViewerHandles($author_phids);

      $view = array();
      foreach ($answers as $answer) {
        $id = $answer->getID();
        $handle = $handles[$answer->getAuthorPHID()];

        $timeline = $this->buildTransactionTimeline(
          $answer,
          id(new PonderAnswerTransactionQuery())
          ->withTransactionTypes(array(PhabricatorTransactions::TYPE_COMMENT)));
        $xactions = $timeline->getTransactions();

        $view[] = id(new PonderAnswerView())
          ->setUser($viewer)
          ->setAnswer($answer)
          ->setTransactions($xactions)
          ->setTimeline($timeline)
          ->setHandle($handle);

      }

      $header = id(new PHUIHeaderView())
        ->setHeader('Answers');


      return id(new PHUIBoxView())
        ->addClass('ponder-answer-section')
        ->appendChild($header)
        ->appendChild($view);
    }

    return null;

  }

}
