<?php

namespace App\Controller;

use AlterPHP\EasyAdminExtensionBundle\Controller\EasyAdminController as BaseAdminController;
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\DateTime;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Class AdminController.
 *
 * Controller for handling requests going to protected area
 */
class AdminController extends BaseAdminController
{
    /**
     * @return array
     */
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), ['fos_user.user_manager' => UserManagerInterface::class]);
    }

    /**
     * Creates and returns a new instance of the User-class.
     *
     * @return \FOS\UserBundle\Model\UserInterface|mixed
     */
    public function createNewUserEntity()
    {
        return $this->get('fos_user.user_manager')->createUser();
    }

    /**
     * Persists an User entity in the database.
     *
     * @param User $user
     */
    public function persistUserEntity($user)
    {
        $this->get('fos_user.user_manager')->updateUser($user, false);
        parent::persistEntity($user);
    }

    /**
     * Updates an User entity.
     *
     * @param User $user
     */
    public function updateUserEntity($user)
    {
        $this->get('fos_user.user_manager')->updateUser($user, false);
        parent::updateEntity($user);
    }

    /**
     * Persists a Survey entity in the database.
     * If the User property in the Survey entity is not set, the currently logged in User will be
     * attached to the Entity.
     *
     * @param object $entity
     */
    public function persistSurveyEntity($entity)
    {
        // Making sure there always is a user attached to the Survey.
        // If the currently logged in User is not an admin, no User will be attached before now
        // due to the User field in the form for creating a Survey is only showed to admins.
        if (empty($entity->getUser())) {
            $entity->setUser($this->getUser());
        }

        parent::persistEntity($entity);
    }

    /**
     * Action for listing Surveys.
     * If the currently logged in User has the admin role assigned, every Survey will be included in the response,
     * otherwise only the Surveys created by the currently logged in User will be included in the response.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function listSurveyAction()
    {
        $this->dispatch(EasyAdminEvents::PRE_LIST);

        $user = $this->getUser();

        // We only want to show Surveys for the currently logged in user
        // except if the currently logged in user has the admin role.
        if (!$this->isGranted('ROLE_ADMIN')) {
            $currentDqlFilter = $this->entity['list']['dql_filter'];

            $currentUserDqlFilter = 'entity.user = '.$user->getId();

            $newDqlFilter = $this->appendDqlFilterToDqlFilter($currentDqlFilter, $currentUserDqlFilter);

            $this->entity['list']['dql_filter'] = $newDqlFilter;
        }

        return $this->listAction();
    }

    /**
     * Generic listAction which now filters fields shown based on currently logged in user.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function listAction()
    {
        $this->dispatch(EasyAdminEvents::PRE_LIST);

        $fields = $this->entity['list']['fields'];

        $this->entity['list']['fields'] = $this->getFilteredListOfFieldsOnRole($fields);

        return parent::listAction();
    }

    /**
     * Custom action for showing a statistics page for a specific Survey.
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function statisticsAction(): Response
    {
        $surveyId = $this->request->query->get('id');

        $dateFormat = 'd/m/Y';

        $defaultFrom = (new \DateTime())->sub(new \DateInterval('P7D'));
        $defaultTo = new \DateTime();

        $defaultValues = [
            'from' => $defaultFrom->format($dateFormat),
            'to' => $defaultTo->format($dateFormat),
        ];
        $form = $this->createFormBuilder($defaultValues)
            ->add('from', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                    new DateTime(['format' => $dateFormat]),
                ],
            ])
            ->add('to', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                    new DateTime(['format' => $dateFormat]),
                ],
            ])
            ->getForm();

        $form->handleRequest($this->request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();

            $answers = $this->getAnswersBetweenDates(
                $surveyId,
                date_create_from_format($dateFormat, $formData['from']),
                date_create_from_format($dateFormat, $formData['to'])
            );

            $averageAnswers = $this->getAnswersBetweenDates(
                $surveyId,
                new \DateTime(date($dateFormat, strtotime(0))),
                date_create_from_format($dateFormat, $formData['from'])
            );

            $avgAnswersWithLabels = $this->getAverageAnswersOnDatesWithLabels($surveyId);

            return $this->render('statistics.html.twig', [
                'form' => $form->createView(),
                'answers' => $answers,
                'averageAnswers' => $averageAnswers,
                'allVotesLabels' => $avgAnswersWithLabels['labels'],
                'allVotesAverage' => $avgAnswersWithLabels['values'],
            ]);
        }

        $defaultPeriodAnswers = $this->getAnswersBetweenDates($surveyId, $defaultFrom, $defaultTo);

        $averageAnswers = $this->getAnswersBetweenDates(
            $surveyId,
            new \DateTime(date($dateFormat, strtotime(0))),
            $defaultFrom
        );

        $avgAnswersWithLabels = $this->getAverageAnswersOnDatesWithLabels($surveyId);

        return $this->render('statistics.html.twig', [
            'form' => $form->createView(),
            'answers' => $defaultPeriodAnswers,
            'averageAnswers' => $averageAnswers,
            'allVotesLabels' => $avgAnswersWithLabels['labels'],
            'allVotesAverage' => $avgAnswersWithLabels['values'],
        ]);
    }

    /**
     * Creates an instance of Symfonys FormInterface based on an Entity and EasyAdmin configuration.
     * Fields wchich has the role property set in the EasyAdmin configuration will be checked against
     * the roles assigned to the currently logged in user, and if they don't match the fields will be removed
     * from the form.
     *
     * @param object $entity
     * @param array  $fields
     * @param string $view
     *
     * @return \Symfony\Component\Form\FormInterface
     *
     * @throws \Exception
     */
    protected function createEntityForm($entity, $fields, $view)
    {
        $form = parent::createEntityForm($entity, $fields, $view);

        // We remove fields from the form if the currently logged in
        // user is not allowed to set a value for a specific field.
        foreach ($fields as $name => $field) {
            if (empty($field['role'])) {
                continue;
            }

            if (!$this->isGranted($field['role'])) {
                $form->remove($name);
            }
        }

        return $form;
    }

    /**
     * Filters the provided list of fields on role if set in config file.
     *
     * @param array $fields Fields needed to be filtered
     *
     * @return array Filtered list of fields
     */
    private function getFilteredListOfFieldsOnRole(array $fields): array
    {
        return array_filter($fields, function ($field) {
            if (!empty($field['role'])) {
                return ($this->isGranted($field['role'])) ? $field : null;
            }

            return $field;
        });
    }

    /**
     * Appends new dql filter to an existing dql filter.
     * If existing dql filter is empty the new dql filter will be returned.
     *
     * @param string $dqlFilter    Dql filter that will have a new filter appended to
     * @param string $newDqlFilter Dql filter that will be appended
     *
     * @return string
     */
    private function appendDqlFilterToDqlFilter($dqlFilter, $newDqlFilter)
    {
        if (empty($dqlFilter)) {
            return $newDqlFilter;
        }

        $dqlFilter .= 'AND '.$newDqlFilter;

        return $dqlFilter;
    }

    /**
     * Returns a list of answer-percentages in a period sorted by answer group (1-5).
     *
     * @param int       $surveyId
     * @param \DateTime $fromDate
     * @param \DateTime $toDate
     *
     * @return array
     *
     * @throws \Exception
     */
    private function getAnswersBetweenDates(int $surveyId, \DateTime $fromDate, \DateTime $toDate): array
    {
        $entityManager = $this->getDoctrine()->getEntityManager();

        $query = $entityManager->createQuery('
            SELECT r.answer,
                   COUNT(r.answer) as answers
            FROM App\Entity\Response r
            WHERE r.survey = :surveyId
            AND r.createdAt BETWEEN :from AND :to
            GROUP BY r.answer
        ');

        $query->setParameter('surveyId', $surveyId);
        $query->setParameter('from', $fromDate->format('Y-m-d'));
        $query->setParameter('to', $toDate->add(new \DateInterval('P1D'))->format('Y-m-d'));

        $values = $query->getResult();

        $totalVotes = 0;

        foreach ($values as $value) {
            $totalVotes += $value['answers'];
        }

        $newValues = [];

        for ($i = 1; $i < 6; ++$i) {
            $answers = 0;
            foreach ($values as $value) {
                if ($i === $value['answer']) {
                    $answers = floor((int) $value['answers'] / $totalVotes * 100);
                }
            }

            $newValues[] = $answers;
        }

        return $newValues;
    }

    /**
     * Returns the average of answers for a Survey sorted by date in ascending order.
     * The array returned has to keys, labels which contains an array with the dates that have answers,
     * and values which contains an array with the average of answers on a date. The first entry in the values
     * array is the average answers for the first entry in the labels array.
     *
     * @param int $surveyId
     *
     * @return array
     */
    private function getAverageAnswersOnDatesWithLabels(int $surveyId): array
    {
        $entityManager = $this->getDoctrine()->getEntityManager();

        $query = $entityManager->createQuery('
            SELECT r.answer,
                   COUNT(r.answer) as answers,
                   DATE(r.createdAt) as dateCreated
            FROM App\Entity\Response r
            WHERE r.survey = :surveyId
            GROUP BY r.answer, dateCreated
            ORDER BY dateCreated ASC
        ');

        $query->setParameter('surveyId', $surveyId);
        $result = $query->getResult();

        $labels = [];

        foreach ($result as $entry) {
            $labels[] = $entry['dateCreated'];
        }

        $labels = array_values(array_unique($labels));

        $values = [];

        $totalSumAnswers = 0;
        $totalSumVotes = 0;
        foreach ($labels as $date) {
            foreach ($result as $entry) {
                if ($date === $entry['dateCreated']) {
                    $totalSumAnswers += $entry['answers'];
                    $totalSumVotes += $entry['answers'] * $entry['answer'];
                }
            }
            $values[] = $totalSumVotes / $totalSumAnswers;
            $totalSumAnswers = 0;
            $totalSumVotes = 0;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
