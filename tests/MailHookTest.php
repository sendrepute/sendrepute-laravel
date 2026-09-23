<?php

namespace SendRepute\Laravel\Tests;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use SendRepute\Laravel\ClassificationResult;
use SendRepute\Laravel\Contracts\Classifier;
use SendRepute\Laravel\Events\ClassificationOutcome;
use SendRepute\Laravel\Listeners\AnalyzeOutgoingMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class MailHookTest extends TestCase
{
    public function test_unmarked_transactional_mail_is_never_analyzed(): void
    {
        $classifier = $this->createMock(Classifier::class);
        $classifier->expects(self::never())->method('classify');
        $listener = $this->listener($classifier);

        self::assertNull($listener->handle(new MessageSending($this->email(), [])));
    }

    public function test_advisory_mode_allows_spam_and_removes_private_marker(): void
    {
        $this->configure('advisory', 'allow');
        $classifier = $this->createMock(Classifier::class);
        $classifier->method('classify')->willReturn(
            new ClassificationResult('id', 'thor', 'spam', 1.0, 'high', false, 1)
        );
        $message = $this->markedEmail();

        self::assertNull($this->listener($classifier)->handle(new MessageSending($message, [])));
        self::assertFalse($message->getHeaders()->has('X-SendRepute-Classify'));
    }

    public function test_blocking_mode_returns_false_to_cancel_send(): void
    {
        $this->configure('blocking', 'allow');
        $classifier = $this->createMock(Classifier::class);
        $classifier->method('classify')->willReturn(
            new ClassificationResult('id', 'thor', 'spam', 0.8, 'high', false, 1)
        );
        $message = $this->markedEmail();

        self::assertFalse($this->listener($classifier)->handle(new MessageSending($message, [])));
    }

    public function test_failure_policy_is_explicit_and_can_fail_closed(): void
    {
        $this->configure('blocking', 'block');
        $classifier = $this->createMock(Classifier::class);
        $classifier->method('classify')->willThrowException(new \RuntimeException('offline'));

        self::assertFalse($this->listener($classifier)->handle(new MessageSending($this->markedEmail(), [])));
    }

    public function test_both_text_and_html_alternatives_are_analyzed(): void
    {
        $this->configure('advisory', 'allow');
        $classifier = $this->createMock(Classifier::class);
        $classifier->expects(self::once())->method('classify')
            ->with('Sender', 'Subject', "Plain body\n\n--- HTML alternative ---\n\n<p>HTML body</p>", null)
            ->willReturn(new ClassificationResult('id', 'thor', 'inbox', 0.1, 'high', false, 1));
        $message = $this->markedEmail()->html('<p>HTML body</p>')->text('Plain body');

        self::assertNull($this->listener($classifier)->handle(new MessageSending($message, [])));
    }

    public function test_failure_emits_only_sanitized_outcome(): void
    {
        $this->configure('blocking', 'allow');
        $classifier = $this->createMock(Classifier::class);
        $classifier->method('classify')->willThrowException(
            new \SendRepute\Laravel\Exceptions\SendReputeException('authentication', 'safe', 401, 'UNAUTHORIZED', 'req-1')
        );
        $seen = null;
        $this->app['events']->listen(ClassificationOutcome::class, function ($event) use (&$seen): void {
            $seen = $event;
        });

        self::assertNull($this->listener($classifier)->handle(new MessageSending($this->markedEmail(), [])));
        self::assertSame('authentication', $seen->category);
        self::assertSame('allowed', $seen->action);
        self::assertSame(
            ['kind', 'action', 'spamProbability', 'category', 'status', 'apiCode', 'requestId'],
            array_keys(get_object_vars($seen))
        );
    }

    public function test_real_laravel_mailer_cancels_before_transport_send(): void
    {
        $this->configure('blocking', 'allow');
        $classifier = $this->createMock(Classifier::class);
        $classifier->method('classify')->willReturn(
            new ClassificationResult('id', 'thor', 'spam', 0.99, 'high', false, 1)
        );
        $this->app->instance(Classifier::class, $classifier);
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $views = $this->createMock(\Illuminate\Contracts\View\Factory::class);
        $mailer = new Mailer('test', $views, $transport, $this->app['events']);

        $result = $mailer->raw('Body', function (Message $message): void {
            $message->from('sender@example.test', 'Sender')
                ->to('recipient@example.test')
                ->subject('Subject');
            $message->getSymfonyMessage()->getHeaders()
                ->addTextHeader('X-SendRepute-Classify', 'yes');
        });

        self::assertNull($result);
    }

    private function configure(string $mode, string $failure): void
    {
        config()->set('sendrepute.paid_analysis_consent', true);
        config()->set('sendrepute.mail', [
            'enabled' => true,
            'opt_in_header' => 'X-SendRepute-Classify',
            'mode' => $mode,
            'spam_probability_threshold' => 0.8,
            'failure_policy' => $failure,
            'model' => null,
        ]);
    }

    private function email(): Email
    {
        return (new Email())->from('Sender <sender@example.test>')->to('recipient@example.test')->subject('Subject')->text('Body');
    }

    private function markedEmail(): Email
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-SendRepute-Classify', 'yes');
        return $email;
    }

    private function listener(Classifier $classifier): AnalyzeOutgoingMessage
    {
        return new AnalyzeOutgoingMessage($classifier, $this->app['events']);
    }
}