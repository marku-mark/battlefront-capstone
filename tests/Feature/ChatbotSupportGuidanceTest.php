<?php

use App\Actions\Inventory\HandleCustomerReturn;
use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CustomerReturnRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotOrchestrationService;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(fn () => Http::preventStrayRequests());

test('shipment replies use recorded tracking and never infer an ETA', function () {
    $order = Order::factory()->withDeliverySnapshot()->create();
    Shipment::factory()->for($order)->create(['tracking_reference' => 'RECORDED-123']);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('Track '.$order->reference, $order->user);

    expect($result['message'])->toContain('RECORDED-123', 'ETA is unavailable', 'not live LBC or GPS tracking');
    expect($result['message'])->not->toContain($order->delivery_address, $order->contact_number);
    ChatbotIntentAgent::assertNeverPrompted();
});

test('cancellation refunds depend on confirmed payments and exclude private notes', function () {
    $reviewer = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create(['total_amount' => '100.00']);
    $workflow = app(OrderProcessingService::class);
    $workflow->requestCancellation($order->user, $order->id, 'PRIVATE CUSTOMER NOTE');
    $workflow->reviewCancellation($order, $reviewer, true, '100.00', 'PRIVATE DECISION NOTE', true);
    $refundId = DB::table('customer_refunds')->sole()->id;
    $paymentId = $this->recordLegacyRefundPayment($refundId, $reviewer, '100.00', 'cash', 'PRIVATE PAYMENT REF');
    ChatbotResponseAgent::fake(['Refund processing.'])->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('Refund status '.$order->reference, $order->user);

    expect($result['message'])->toBe('Refund processing.');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Refund processing')
        && ! str_contains($prompt->prompt, 'Refund completed') && ! str_contains($prompt->prompt, 'PRIVATE')
        && ! str_contains($prompt->prompt, 'payment-proofs') && ! str_contains($prompt->prompt, $order->recipient_name));

    app(HandleCustomerReturn::class)->reviewPayment($paymentId, User::factory()->administrator()->create(), 'verified', 'PRIVATE RECONCILIATION NOTE');
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $confirmed = app(ChatbotOrchestrationService::class)->respond('Refund status '.$order->reference, $order->user);

    expect($confirmed['message'])->toContain('Refund completed')->not->toContain('PRIVATE');
    ChatbotIntentAgent::assertNeverPrompted();
});

test('return summaries clarify multiple requests and never expose another owners request', function () {
    $order = Order::factory()->create();
    $firstItem = OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => 'Logitech Mouse']))->create(['product_name_at_time' => 'Logitech Mouse']);
    $secondItem = OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => 'Aurelius Keyboard']))->create(['product_name_at_time' => 'Aurelius Keyboard']);
    $first = CustomerReturnRequest::factory()->create(['order_item_id' => $firstItem->id, 'reason' => 'PRIVATE REASON']);
    CustomerReturnRequest::factory()->create(['order_item_id' => $secondItem->id]);
    $chat = app(ChatbotOrchestrationService::class);

    $result = $chat->respond('My return status '.$order->reference, $order->user);

    expect($result['message'])->toContain('Which request', 'Logitech Mouse', 'Aurelius Keyboard')->not->toContain('PRIVATE');
    ChatbotResponseAgent::assertNeverPrompted();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $chosen = $chat->respond('Return request '.$first->id.' for '.$order->reference, $order->user);
    expect($chosen['message'])->toContain('Awaiting item receipt')->not->toContain('Aurelius Keyboard', 'PRIVATE');
    $missing = $chat->respond('Return request 999999 for '.$order->reference, $order->user);
    expect($missing['message'])->toContain('No matching')->not->toContain('Logitech Mouse');
    expect($chat->respond('My return status '.$order->reference, User::factory()->create())['message'])->toContain("couldn't find a matching order");
    ChatbotIntentAgent::assertNeverPrompted();
});

test('physical return refunds distinguish approved recorded and confirmed amounts', function () {
    Storage::fake('local');
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $product = Product::factory()->available()->create(['name' => 'Logitech Mouse']);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1, 'price_at_time' => '100.00']);
    $order->sale()->create(['amount' => '100.00', 'sale_date' => today()]);
    $request = CustomerReturnRequest::factory()->create(['order_item_id' => $item->id]);
    $receiver = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id')]);
    $reviewer = User::factory()->administrator()->create();
    $workflow = app(HandleCustomerReturn::class);
    $returnId = app(HandleCustomerReturnRequest::class)->receive($receiver, $request->id, 'opened', [UploadedFile::fake()->image('inspection.jpg')]);
    $workflow->decide($returnId, $reviewer, true, 'write_off', '80.00', 'PRIVATE INSPECTION', resolution: ['type' => 'refund']);
    $paymentId = $this->recordLegacyRefundPayment(DB::table('customer_refunds')->sole()->id, $reviewer, '40.00', 'cash', 'PRIVATE PAYMENT REF');
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $chat = app(ChatbotOrchestrationService::class);

    $recorded = $chat->respond('My refund status '.$order->reference, $order->user);
    expect($recorded['message'])->toContain('Refund processing', 'Approved refund: PHP 80.00', 'Confirmed paid: PHP 0.00', 'Remaining: PHP 80.00')->not->toContain('PRIVATE');

    $workflow->reviewPayment($paymentId, User::factory()->administrator()->create(), 'verified', 'PRIVATE RECONCILIATION');
    $confirmed = $chat->respond('My refund status '.$order->reference, $order->user);
    expect($confirmed['message'])->toContain('Refund processing', 'Confirmed paid: PHP 40.00', 'Remaining: PHP 40.00')->not->toContain('PRIVATE');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->prompt, 'PRIVATE') && ! str_contains($prompt->prompt, 'inspection.jpg'));
    ChatbotIntentAgent::assertNeverPrompted();
});

test('order support summaries refresh ownership and request status on localized follow ups', function () {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create();
    $request = CustomerReturnRequest::factory()->create(['order_item_id' => $item->id]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('My return status '.$order->reference, $order->user, null, 'session');
    $request->forceFill(['withdrawn_at' => now()])->save();

    $next = $chat->respond('Return status nito?', $order->user, $first['context_token'], 'session');

    expect($next['message'])->toContain('Withdrawn');
    $foreign = $chat->respond('Return status nito?', User::factory()->create(), $next['context_token'], 'session');
    expect($foreign['message'])->not->toContain($order->reference, 'Withdrawn');
    ChatbotIntentAgent::assertNeverPrompted();
});

test('distinct rule questions clarify and resume only the selected guidance', function () {
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Paano magbayad kag pwede ibalik ang item?', null, null, 'session');
    expect($first['message'])->toContain('payment guidance', 'return guidance');

    $next = $chat->respond('Returns', null, $first['context_token'], 'session');

    expect($next['message'])->toContain('physical item receipt')->not->toContain('Pickup orders support');
    ChatbotResponseAgent::assertNeverPrompted();
    ChatbotIntentAgent::assertNeverPrompted();
});

test('web and mobile preserve response fields and include language metadata', function (string $channel) {
    Product::factory()->available()->create(['name' => 'Logitech Mouse', 'price' => '899.00']);
    ChatbotResponseAgent::fake(['Ang presyo ay PHP 899.00.'])->preventStrayPrompts();

    $response = $this->postJson(route($channel === 'web' ? 'chatbot.store' : 'api.v1.chatbot.store'), ['message' => 'Magkano ang Logitech Mouse?']);

    $response->assertOk();
    $data = $channel === 'web' ? $response->json() : $response->json('data');
    expect(array_keys($data))->toBe(['message', 'presentation', 'source', 'context_token', 'language']);
    expect($data['language'])->toBe('tl');
    expect($data['source'])->toBe('gemini');
    expect($data['message'])->toBe('Ang presyo ay PHP 899.00.');
    expect($data['context_token'])->toBeString();
    ChatbotIntentAgent::assertNeverPrompted();
})->with(['web', 'mobile']);

test('localized application guidance cannot be saved as an overriding FAQ', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $this->postJson(route('administration.chatbot-knowledge.preview'), ['message' => 'Paano magbayad?'])
        ->assertOk()->assertJsonPath('owner', 'application_rules')->assertJsonPath('eligible_for_faq', false);
    $this->postJson(route('administration.chatbot-knowledge.store'), ['question_pattern' => 'Paano magbayad?', 'response_template' => 'Incorrect override'])
        ->assertUnprocessable()->assertJsonValidationErrors('question_pattern');
    $this->assertDatabaseCount('chatbot_knowledge', 0);
    ChatbotResponseAgent::assertNeverPrompted();
    ChatbotIntentAgent::assertNeverPrompted();
});
