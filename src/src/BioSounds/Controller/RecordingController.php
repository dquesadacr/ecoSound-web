<?php

namespace BioSounds\Controller;

use BioSounds\Entity\IndexLog;
use BioSounds\Entity\Recording;
use BioSounds\Entity\RecordingFft;
use BioSounds\Entity\UserPermission;
use BioSounds\Entity\Permission;
use BioSounds\Entity\User;
use BioSounds\Exception\InvalidParameterException;
use BioSounds\Exception\NotAuthenticatedException;
use BioSounds\Presenter\FrequencyScalePresenter;
use BioSounds\Presenter\RecordingPresenter;
use BioSounds\Presenter\TagPresenter;
use BioSounds\Provider\CollectionProvider;
use BioSounds\Provider\IndexTypeProvider;
use BioSounds\Provider\LabelAssociationProvider;
use BioSounds\Provider\LabelProvider;
use BioSounds\Provider\ProjectProvider;
use BioSounds\Provider\RecordingProvider;
use BioSounds\Provider\TagProvider;
use BioSounds\Service\RecordingService;
use BioSounds\Utils\Auth;
use BioSounds\Utils\Utils;
use Twig\Environment;
use Symfony\Component\Process\Process;

class RecordingController extends BaseController
{
    const DEFAULT_TAG_COLOR = '#FFFFFF';
    const SOUND_PATH = 'sounds/sounds/%s/%s/%s';
    const IMAGE_SOUND_PATH = 'sounds/images/%s/%s/%s';

    private $recordingId;
    private $recordingService;

    /**
     * @var RecordingPresenter
     */
    private $recordingPresenter;

    /**
     * RecordingController constructor.
     * @param Environment $twig
     */
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);

        $this->recordingPresenter = new RecordingPresenter();
        $this->recordingService = new RecordingService();
        $this->recordingPresenter->setSpectrogramHeight(SPECTROGRAM_HEIGHT);
        $user = new User();
        if ($user->getFftValue(Auth::getUserID())) {
            $fftSize = $user->getFftValue(Auth::getUserID());
        } elseif (Utils::getSetting('fft')) {
            $fftSize = Utils::getSetting('fft');
        }
        $this->fftSize = $fftSize;
    }

    /**
     * @param int $id
     * @param int $collectionPage
     * @return string
     * @throws \Exception
     */
    public function show(int $id)
    {
        if (empty($id)) {
            throw new \Exception(ERROR_EMPTY_ID);
        }
        $recording = new RecordingFft();
        if ($recording->getUserRecordingFft(Auth::getUserID(), $id)) {
            $this->fftSize = $recording->getUserRecordingFft(Auth::getUserID(), $id);
        }
        $this->recordingId = $id;
        $recordingData = (new RecordingProvider())->get($this->recordingId);

        //TODO: Remove when recording is an Entity. Add to it.
        $recordingData['collection'] = (new CollectionProvider())->get($recordingData[Recording::COL_ID]);

        $this->recordingPresenter->setRecording($recordingData);

        if (isset($_POST['showTags'])) {
            $this->recordingPresenter->setShowTags(filter_var($_POST['showTags'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($_POST['continuous_play'])) {
            $this->recordingPresenter->setContinuousPlay(
                filter_var($_POST['continuous_play'], FILTER_VALIDATE_BOOLEAN)
            );
        }
        if (isset($_POST['estimateDistID'])) {
            $this->recordingPresenter->setEstimateDistID(filter_var($_POST['estimateDistID'], FILTER_VALIDATE_INT));
        }
        $this->setCanvas($recordingData);
        return $this->twig->render('recording/recording.html.twig', [
            'project' => (new ProjectProvider())->get($this->recordingPresenter->getRecording()['collection']->getProject()),
            'player' => $this->recordingPresenter,
            'sound' => $this->recordingPresenter->getRecording(),
            'frequency_data' => $this->recordingPresenter->getFrequencyScaleData(),
            'title' => sprintf(self::PAGE_TITLE, $recordingData[Recording::NAME]),
            'labels' => Auth::isUserLogged() ? (new LabelProvider())->getBasicList(Auth::getUserLoggedID()) : '',
            'myLabel' => Auth::isUserLogged() ? (new LabelAssociationProvider())->getUserLabel($id, Auth::getUserLoggedID()) : '',
            'indexs' => Auth::isUserLogged() ? (new IndexTypeProvider())->getList() : '',
            'ffts' => [4096, 2048, 1024, 512, 256, 128,],
            'fftsize' => $this->fftSize,
        ]);
    }

    /**
     * @param int $id
     * @return false|string
     * @throws \Exception
     */
    public function details(int $id)
    {
        if (empty($id)) {
            throw new \Exception(ERROR_EMPTY_ID);
        }

        $recording = (new RecordingProvider())->getSimple($id);
        if ($recording->getCollection() != 1 && $recording->getCollection() != 3 && !Auth::isUserLogged()) {
            throw new NotAuthenticatedException();
        }

        return json_encode([
            'errorCode' => 0,
            'data' => $this->twig->render('recording/fileInfo.html.twig', [
                'recording' => $recording,
            ]),
        ]);
    }

    /**
     * @param $recordingData
     * @throws \Exception
     */
    private function setCanvas($recordingData)
    {
        $fileName = explode('.', $recordingData[Recording::FILENAME]);
        $imageFilePath = null;

        $originalMp3FilePath = sprintf(
            $this::SOUND_PATH,
            $recordingData[Recording::COL_ID],
            $recordingData[Recording::DIRECTORY],
            $fileName[0] . '.mp3'
        );
        $originalSoundFilePath = sprintf(
            $this::SOUND_PATH,
            $recordingData[Recording::COL_ID],
            $recordingData[Recording::DIRECTORY],
            $recordingData[Recording::FILENAME]
        );
        $originalWavFilePath = sprintf(
            $this::SOUND_PATH,
            $recordingData[Recording::COL_ID],
            $recordingData[Recording::DIRECTORY],
            $fileName[0] . '.wav'
        );

        if (!empty($recordingData['ImageFile'])) {
            $imageFilePath = sprintf(
                $this::IMAGE_SOUND_PATH,
                $recordingData[Recording::COL_ID],
                $recordingData[Recording::DIRECTORY],
                $recordingData['ImageFile']
            );
        }
        $duration = $recordingData[Recording::DURATION];
        $samplingRate = $recordingData[Recording::SAMPLING_RATE];

        $this->recordingPresenter->setChannel(
            isset($_POST['channel']) ? filter_var($_POST['channel'], FILTER_SANITIZE_NUMBER_INT) : 1
        );

        $filter = false;
        $minFrequency = 1;
        $maxFrequency = $samplingRate / 2;
        $minTime = 0;
        $maxTime = $duration;

        /* Get the spectrogram selection values to generate zoom and filter */
        if (isset($_POST['t_min']) && isset($_POST['t_max']) && isset($_POST['f_min']) && isset($_POST['f_max'])) {
            $minTime = $_POST['t_min'];
            $maxTime = $_POST['t_max'];
            $minFrequency = $_POST['f_min'];
            $maxFrequency = $_POST['f_max'];
            if (isset($_POST['filter'])) {
                $filter = filter_var($_POST['filter'], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (isset($_GET['t_min']) && isset($_GET['t_max']) && isset($_GET['f_min']) && isset($_GET['f_max'])) {
            $minTime = $_GET['t_min'];
            $maxTime = $_GET['t_max'];
            $minFrequency = $_GET['f_min'];
            $maxFrequency = $_GET['f_max'];
        }

        // Spectrogram Image Width
        $spectrogramWidth = WINDOW_WIDTH - (SPECTROGRAM_LEFT + SPECTROGRAM_RIGHT);
        $this->recordingPresenter->setSpectrogramWidth($spectrogramWidth);
        $recording = new RecordingFft();
        if ($recording->getUserRecordingFft(Auth::getUserID(), $recordingData[Recording::ID])) {
            $recording_fft = $recording->getUserRecordingFft(Auth::getUserID(), $recordingData[Recording::ID]);
        }
        $selectedFileName = $fileName[0] . '_' . $minFrequency . '-' . $maxFrequency . '_' . $minTime . '-'
            . $maxTime . '_' . $this->fft . '_' . $this->recordingPresenter->getChannel();

        if (!file_exists($originalWavFilePath)) {
            Utils::generateWavFile($originalSoundFilePath);
        }

        #Generate a random number and store in session
        if (!isset($_SESSION['random_id'])) {
            $randomID = mt_rand();
            $_SESSION['random_id'] = $randomID;
        } else {
            $randomID = $_SESSION['random_id'];
        }

        if (!file_exists("tmp/$randomID")) {
            @mkdir("tmp/$randomID");
        }

        $spectrogramImagePath = 'tmp/' . $randomID . '/' . $selectedFileName . '.png';
        //$soundFileView =  'tmp/' . $randomID .'/'. $selectedFileName . '.mp3';
        $zoomedFilePlayer = 'tmp/' . $randomID . '/' . $selectedFileName . '.ogg';
        $zoomedFilePath = 'tmp/' . $randomID . '/' . $selectedFileName . '.' . $fileName[1];

        /* If spectrogram doesn't exist, generate */
        if (!file_exists($zoomedFilePlayer)) {
            $durationTime = round(($maxTime - $minTime) * $samplingRate); //Set to number of samples
            $startTime = round($minTime * $samplingRate); //Set to number of samples
            //$tempPath = 'tmp/' . $randomID .'/';
            $selectionFilePath = $filter ? 'tmp/1.' . $selectedFileName . '.' . $fileName[1] : $zoomedFilePath;

            if ($minTime != 0 || $maxTime != $duration) {
                Utils::generateSoundFileSelection(
                    $originalSoundFilePath,
                    $selectionFilePath,
                    $startTime,
                    $durationTime
                );
                if ($filter) {
                    Utils::filterFrequenciesSound(
                        $selectionFilePath,
                        $zoomedFilePath,
                        $minFrequency,
                        ($maxFrequency == $samplingRate / 2) ? $maxFrequency - 1 : $maxFrequency
                    );
                }
            } else {
                //  				if (is_file($originalMp3FilePath)) {
                //					copy($originalMp3FilePath, $soundFileView);
                //				}
                if ($this->recordingPresenter->getChannel() == 1 && is_file($imageFilePath)) {
                    copy($imageFilePath, $spectrogramImagePath);
                }
                // copy($originalWavFilePath, $wavFilePath);
                copy($originalSoundFilePath, $zoomedFilePath);
            }

            /* Generation MP3 File */
            if (!file_exists($zoomedFilePlayer)) {
                $zoomedFilePlayer = $zoomedFilePath;

                if ($samplingRate <= 44100) {
                    $zoomedFilePlayer = Utils::convertToMp3($zoomedFilePath);
                }
                if ($samplingRate > 44100 && $samplingRate <= 192000) {
                    $zoomedFilePlayer = Utils::convertToOgg($zoomedFilePath);
                }
            }
        }

        $this->recordingService->generateSpectrogramImage(
            $spectrogramImagePath,
            Utils::generateWavFile($zoomedFilePath),
            $maxFrequency,
            $recordingData[Recording::ID],
            $this->recordingPresenter->getChannel(),
            $minFrequency
        );
        $this->recordingPresenter->setMinTime(round($minTime, 1));
        $this->recordingPresenter->setMaxTime(round($maxTime, 1));
        $this->recordingPresenter->setMinFrequency($minFrequency);
        $this->recordingPresenter->setMaxFrequency($maxFrequency);
        $this->recordingPresenter->setDuration($duration);
        $this->recordingPresenter->setFileFreqMax($samplingRate / 2);
        $this->recordingPresenter->setFilePath(APP_URL . '/' . $zoomedFilePlayer);
        $this->recordingPresenter->setImageFilePath(APP_URL . '/' . $spectrogramImagePath);
        $this->recordingPresenter->setUser(empty(Auth::getUserID()) ? 0 : Auth::getUserID());

        $this->generateFrequenciesScale($maxFrequency, $minFrequency);
        $this->setTags($minTime, $maxTime, $minFrequency, $maxFrequency, $spectrogramWidth);
        $this->setTime($maxTime, $minTime);
        $this->recordingService->setViewPort(
            $this->recordingPresenter,
            $samplingRate,
            $this->recordingPresenter->getChannel(),
            $originalWavFilePath
        );

        //$this->setViewPort($samplingRate, $this->recordingPresenter->getChannel(), $originalWavFilePath);
    }

    /**
     * @param int $maxFrequency
     * @param int $minFrequency
     */
    private function generateFrequenciesScale(int $maxFrequency, int $minFrequency)
    {
        $range = $maxFrequency - $minFrequency;
        $steps = round($range / 4);
        $freqDigits = strlen((string)$steps);
        $freqMid1 = round($minFrequency + $steps, -$freqDigits + 1);
        $freqMid2 = round($minFrequency + ($steps * 2), -$freqDigits + 1);
        $freqMaxHeight = SPECTROGRAM_HEIGHT * ((($maxFrequency - $freqMid2) / $range) / 2);
        $freqMid2Height = $freqMaxHeight / 2 + SPECTROGRAM_HEIGHT * ((($freqMid2 - $freqMid1) / $range) / 2);
        $freqMid1Height = $freqMid2Height / 2 + SPECTROGRAM_HEIGHT * ((($freqMid1 - $minFrequency) / $range) / 2);
        $freqMinHeight = $freqMid1Height / 2 + SPECTROGRAM_HEIGHT * ((($freqMid1 - $minFrequency) / $range) / 2);

        $this->recordingPresenter->setFrequencyScaleData(
            (new FrequencyScalePresenter())
                ->setFreqMaxHeight($freqMaxHeight)
                ->setFreqMax($maxFrequency)
                ->setFreqMid1($freqMid1)
                ->setFreqMid1Height($freqMid1Height)
                ->setFreqMid2($freqMid2)
                ->setFreqMid2Height($freqMid2Height)
                ->setFreqMin($minFrequency)
                ->setFreqMinHeight($freqMinHeight)
        );
    }

    /**
     * @param $maxTime
     * @param $minTime
     */
    private function setTime($maxTime, $minTime)
    {
        $step = 10.85;
        $dur = $maxTime - $minTime;
        $dur_ea = $dur / $step;
        $second1 = $minTime;

        for ($i = 0; $i < 11; $i++) {
            if ($i > 0)
                $second1 = $second1 + $dur_ea;
            $second = round($second1, 0);
            if ($dur_ea < 1) {
                $second = round($second1, 1);
            }
            $this->recordingPresenter->addTimeScaleSecond($second);
        }
    }

    /**
     * @param $viewTimeMin
     * @param $viewTimeMax
     * @param $viewFreqMin
     * @param $viewFreqMax
     * @param $specWidth
     * @throws \Exception
     */
    private function setTags(
        float $viewTimeMin,
        float $viewTimeMax,
        float $viewFreqMin,
        float $viewFreqMax,
        float $specWidth
    )
    {
        $tagProvider = new TagProvider();
        $viewPermission = false;
        $reviewPermission = false;

        if (!Auth::isUserAdmin()) {
            $userPerm = new UserPermission();
            $perm = empty(Auth::getUserLoggedID()) ? null : $userPerm->getUserColPermission(
                Auth::getUserLoggedID(),
                $this->recordingPresenter->getRecording()[Recording::COL_ID]
            );

            if (empty($perm)) {
                $perm = -1;
            }

            $_SESSION['user_col_permission'] = $perm;

            $permission = new Permission();
            $reviewPermission = $permission->isReviewPermission($perm);
            $viewPermission = $permission->isViewPermission($perm);
            $managePermission = $permission->isManagePermission($perm);
        }

        if (Auth::isUserAdmin() || $reviewPermission || $viewPermission || $managePermission) {
            $tags = $tagProvider->getList($this->recordingId);
        } else {
            $tags = $tagProvider->getList($this->recordingId, Auth::getUserLoggedID());
        }

        if (!empty($tags)) {
            $viewTotalTime = $viewTimeMax - $viewTimeMin;
            $viewFreqRange = $viewFreqMax - $viewFreqMin;

            $i = count($tags);
            $user = new User();

            $listTags = [];
            foreach ($tags as $tag) {
                $tagID = $tag->getId();
                $tagTimeMax = $tag->getMaxTime();
                $tagTimeMin = $tag->getMinTime();
                $tagFreqMin = $tag->getMinFrequency();
                $tagFreqMax = $tag->getMaxFrequency();
                $tagStyle = empty($tag->getCallDistance()) && empty($tag->isDistanceNotEstimable()) && $tag->getPhony() == 'biophony' ? 'tag-orange' : '';
                $tagStyle = empty($tag->getReviewNumber()) ? $tagStyle . ' tag-dashed' : $tagStyle;
                $tagSoundType = $tag->getSoundType();
                $tagPhony = $tag->getPhony();
                if (empty($userTagColor = $user->getTagColor($tag->getUser()))) {
                    $userTagColor = self::DEFAULT_TAG_COLOR;
                }

                // Only show if some part of the tag is inside the current window
                if (
                    $tagTimeMin < $viewTimeMax && $tagTimeMax > $viewTimeMin
                    && $tagFreqMin < $viewFreqMax && $tagFreqMax > $viewFreqMin
                ) {

                    //Time and freq calculations to draw the boxes of tags
                    $tagTimeMax = ($tagTimeMax > $viewTimeMax) ? $viewTimeMax : $tagTimeMax;

                    if ($tagTimeMin < $viewTimeMin) {
                        $time_i = 0;
                        $tagTimeMin = $viewTimeMin;
                    } else
                        $time_i = (($tagTimeMin - $viewTimeMin) / $viewTotalTime) * $specWidth;

                    if ($tagFreqMax > $viewFreqMax) {
                        $freq_i = 0;
                        $tagFreqMax = $viewFreqMax;
                    } else
                        $freq_i = ((($viewFreqRange + $viewFreqMin) - $tagFreqMax) / $viewFreqRange) * SPECTROGRAM_HEIGHT;

                    $freq_w = $tagFreqMin < $viewFreqMin ? SPECTROGRAM_HEIGHT - $freq_i : (($tagFreqMax - $tagFreqMin) / $viewFreqRange) * SPECTROGRAM_HEIGHT;

                    $time_w = (($tagTimeMax - $tagTimeMin) / $viewTotalTime) * $specWidth;

                    $pos = $i + 800;
                    $listTags[] = (new TagPresenter())
                        ->setId($tagID)
                        ->setPosition($pos)
                        ->setTitle($tag->getSpeciesName())
                        ->setHeight($freq_w)
                        ->setWidth($time_w)
                        ->setLeft($time_i)
                        ->setTop($freq_i)
                        ->setStyle($tagStyle)
                        ->setColor($userTagColor)
                        ->setSoundType($tagSoundType)
                        ->setPhony($tagPhony);
                    $i--;
                }
            }
            $this->recordingPresenter->setTags($listTags);
        }
    }

    public function update()
    {
        if (!Auth::isUserLogged()) {
            throw new NotAuthenticatedException();
        }

        $data = [];
        foreach ($_POST as $key => $value) {
            $data[$key] = $value;
        }

        if ((new LabelAssociationProvider())->setEntry($data) > 0) {
            return json_encode([
                'errorCode' => 0,
                'message' => 'Recording Label set successfully.'
            ]);
        }
    }


    public function addlabel()
    {
        return json_encode([
            'errorCode' => 0,
            'data' => $this->twig->render('recording/player/newLabel.html.twig')
        ]);
    }

    public function getMaadlabel(int $id)
    {
        return $this->twig->render('recording/player/maadLabel.html.twig', [
            'index' => (new IndexTypeProvider())->get($id),
        ]);
    }

    public function maad()
    {
        if (!Auth::isUserLogged()) {
            throw new NotAuthenticatedException();
        }
        foreach ($_POST as $key => $value) {
            $data[$key] = $value;
        }
        $str = 'python3 ' . ABSOLUTE_DIR . 'bin/getMaad.py' .
            ' -f ' . ABSOLUTE_DIR . 'tmp/' . implode('.', explode('.', explode('/tmp/', $data['filename'])[1], -1)) .
            ' --ch ' . ($data['channel'] == 2 ? 'right' : 'left') .
            ' --mint ' . $data['minTime'] .
            ' --maxt ' . $data['maxTime'] .
            ' --minf ' . $data['minFrequency'] .
            ' --maxf ' . $data['maxFrequency'];
        if ($data['index'] != '') {
            $str = $str . ' --it ' . $data['index'];
        }
        if ($data['param'] != '') {
            $str = $str . ' --pa ' . substr($data['param'], 0, -1);
        }
        exec($str . " 2>&1", $out, $status);
        if ($status == 0 && $out[count($out) - 1] != "0") {
            $result = $out[count($out) - 1];
            if ($data['channel_num'] == 1) {
                $channel = 'Mono';
            } elseif ($data['channel'] == 2) {
                $channel = 'Right';
            } else {
                $channel = 'Left';
            }
            if ($data['index'] == '') {
                return $result;
            } else {
                return json_encode([
                    'errorCode' => 0,
                    'data' => $this->twig->render('recording/player/maadResult.html.twig', [
                        'title' => $data['index'],
                        'result' => $result,
                        'recording_id' => $data['recording_id'],
                        'index_id' => $data['index_id'],
                        'minTime' => $data['minTime'],
                        'maxTime' => $data['maxTime'],
                        'minFrequency' => $data['minFrequency'],
                        'maxFrequency' => $data['maxFrequency'],
                        'param' => substr('Channel?' . $channel . '@' . $data['param'], 0, -1),
                    ])
                ]);
            }
        } else {
            return json_encode([
                'errorCode' => 0,
                'data' => $this->twig->render('recording/player/maadResult.html.twig', [
                    'title' => 'Invalid Parameter',
                    'result' => '',
                ])
            ]);
        }
    }


    public function saveLabel()
    {
        if (!Auth::isUserLogged()) {
            throw new NotAuthenticatedException();
        }

        $data = [];
        foreach ($_POST as $key => $value) {
            $data[$key] = $value;
        }

        $lblName = $data["cust_label"];
        $bcda = 'bbbb::';
        foreach ($data as $k1 => $v1) {
            $bcda .= $k1 . '=' . $v1 . ';';
        }

        (new LabelProvider())->newLabel(Auth::getUserLoggedID(), $lblName);

        return json_encode([
            'errorCode' => 0,
            'message' => 'Recording Label created successfully.'
        ]);
    }

    public function saveMaadResult()
    {
        if (!Auth::isUserLogged()) {
            throw new NotAuthenticatedException();
        }

        $data = [];
        $data['user_id'] = Auth::getUserLoggedID();

        foreach ($_POST as $key => $value) {
            $data[$key] = $value;
        }
        (new IndexLog())->insert($data);
        return json_encode([
            'errorCode' => 0,
            'message' => 'Index saved successfully.'
        ]);
    }
}
