<?php namespace App\Command;

use App\Object\Choice;
use App\Object\Page;
use App\Object\Question;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use GuzzleHttp\Client;

class PullSurveyQuestionsCommand extends Command
{
    protected $client;

    // the name of the command (the part after "php command.php")
    protected static $defaultName = 'survey:pull-questions';

    public function __construct(Client $guzzleClient)
    {
        parent::__construct();
        $this->client = $guzzleClient;
    }

    public function getPage($apiPageRecord)
    {
        $exists = Page::find($apiPageRecord['id']);
        if ($exists) {
            return $exists;
        }

        $new = new Page();
        $new->title = strip_tags($apiPageRecord['title']);
        $new->survey_id = getenv('SURVEY_ID');
        $new->description = strip_tags($apiPageRecord['description']);
        $new->page_id = $apiPageRecord['id'];
        $new->url = $apiPageRecord['href'];
        $new->save();
        $exists = Page::find($apiPageRecord['id']);

        return $exists;
    }

    protected function configure()
    {
        // ...
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $headers = [
            'headers' => [
                'Authorization' => 'Bearer ' . getenv('SURVEYMONKEY_TOKEN'),
                'Accept'        => 'application/json',
            ]
        ];

        $response = $this->client->request('GET', getenv('SURVEY_ID').'/details', $headers)->getBody()->getContents();
        $response = json_decode($response, true);

        $pages = $response['pages'];
        $output->writeln([
            'Pull Survey Questions',
            '============'
        ]);
        $pageCount = 0;
        $questionCount = 0;
        foreach ($pages as $each) {
            $page = $this->getPage($each);
            $pageCount++;

            //$output->writeln("Pulling data from page: ".strip_tags($page->title));
            foreach ($each['questions'] as $question) {
                $questionCount++;
                $questionId = $question['id'];

                $record = Question::find($questionId);
                if (!$record) {
                    $record = new Question();
                    $record->question_id = $question['id'];
                    $record->url = $question['href'];
                    $record->question = strip_tags($question['headings'][0]['heading']);
                    $record->prompt_type = $question['family'];
                    $record->prompt_subtype = $question['subtype'];

                    $record->page()->associate($page);
                    $record->save();
                }
                if (strpos($question['family'], 'choice') == false &&
                    strpos($question['subtype'], 'single') !== false) {
                    if ($question["validation"] == NULL) {
                        continue;
                    }
                    $record->min = $question["validation"]['min'];
                    $record->max = $question["validation"]['max'];
                    $record->save();

                    continue;
                }

                if (strpos($question['family'], 'choice') !== false) {
                    $choices = $question['answers']['choices'];
                    foreach ($choices as $choice) {
                        $choiceId = $choice['id'];
                        $exists = Choice::find($choiceId);
                        if ($exists) {
                            continue;
                        }
                        $add = new Choice();
                        $add->choice_id = $choiceId;
                        $add->choice = $choice['text'];
                        $add->question()->associate(Question::find($questionId));
                        $add->save();
                    }
                }
            }
        }
        $output->writeln("Pulled $pageCount pages with a total of $questionCount questions.");
    }
}