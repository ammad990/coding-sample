<?php

namespace App\Http\Controllers;

use App\Glumen\Domains\Order\Jobs\CancelOrderJob;
use App\Model\Orm\CancellationReason;
use App\Model\Orm\Invoice;
use App\Model\Orm\InvoiceOrder;
use App\Model\Orm\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Model\Orm\BuyBackOrder;
use App\Model\Orm\FeedbackOption;
use App\Model\Orm\PartnerToken;
use App\Model\Orm\TradeinPartner;
use App\Model\Orm\TradeinOrder;
use App\Model\Orm\UserFeedback;
use Illuminate\Support\Facades\DB;
use App\Model\Data\Country;
use App\Model\Orm\Modern\Dispute;

class OrderController extends Controller
{
    public function index()
    {
        $status = request()->has('status')? request()->status: '';
        $country = request()->has('country')? request()->country: '';
        if($status == 'buyback')
        {
            if($country == 'ksa')
            {
                $models = TradeinOrder::with('items.device')
                ->where('user_id', auth()->id())
                ->where('tradein_orders.country_id',2)
                ->orderBy('tradein_orders.id','DESC')
                ->paginate(20);
            }
            else
            {
                $country_id = Country::getInstance()->getCountryId();
                $models = TradeinOrder::with('items.device')
                ->where('user_id', auth()->id())
                ->where('tradein_orders.country_id',$country_id)
                ->orderBy('tradein_orders.id','DESC')
                ->paginate(20);
            }

            $orderPendingForSchedule = false;
            foreach ($models as $model) {
                if ($model->order_status == BuyBackOrder::STATUS_ORDER_RECIEVED) {
                    $orderPendingForSchedule = true;
                    break;
                }
            }
            $partner = TradeinPartner::where('slug',"cartlow-buyback" )->first();
            $partnerToken = PartnerToken::where(['partner_id' => $partner->id,'active' => 1])->first();
            return view('order.index', [
                'status' => false,
                'models' => $models,
                'notice' => $orderPendingForSchedule,
                'partnerToken' => $partnerToken->token,
                'model_status' => 'buyback',
            ]);
        }
        else if($status == 'returns')
        {
            $country_id = Country::getInstance()->getCountryId();
            $this->country_name = Country::getInstance()->getCountryById($country_id);
            $models = Dispute::with('order')->whereHas('order', function($q)
            {
                $q->where('country',$this->country_name);
            })->where('user_id',auth()->id())->orderBy('id', 'DESC')->paginate(20);
            return view('order.index', [
                'models' => $models,
                'model_status' => $status,
            ]);
        }
        else
        {
            $country_id = Country::getInstance()->getCountryId();
            $country_name = Country::getInstance()->getCountryById($country_id);
            $models = Order::where('userid', Auth::user()->id)->where('deleted', false);
            $order_status = strtolower(str_replace(' ', '_', $status));
            switch ($order_status) {
                case 'processing':
                case 'packed':
                case 'pending':
                case 'pending_payment':
                case 'order_placed':
                case 'shipped':
                    $order_status = 'processing';
                    $models->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_DELIVERED, Order::STATUS_PENDING_PAYMENT, Order::STATUS_RETURNED]);
                    break;
                case 'cancelled':
                    $models->where('status',Order::STATUS_CANCELLED);
                    break;
                case 'delivered':
                    $models->where('status',Order::STATUS_DELIVERED);
                    break;
                default:
                    $models->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_DELIVERED, Order::STATUS_PENDING_PAYMENT, Order::STATUS_RETURNED]);
            }
            $models = $models->where('country',$country_name)->orderBy('orderid', 'DESC')->paginate(20);
            return view('order.index', [
                'models' => $models,
                'model_status' => $order_status,
            ]);
        }
    }

    public function view()
    {
        $model = Order::where('userid', Auth::user()->id)
            ->where('deleted', false)
            ->whereNotIn('status', [
                Order::STATUS_PENDING_PAYMENT
            ])
            ->where('orderid', request()->get('id'))
            ->firstOrFail();

        $feedback_options = [];

        $selected_feedback = null;
        $selected_feedback_choices = [];

        if($model->status == Order::STATUS_DELIVERED){
            // selected feedback data
            $selected_feedback = UserFeedback::where('order_id', $model->orderid)->orderBy('created_at', 'DESC')->first();
            if(null != $selected_feedback){
                // dd($selected_feedback->feedbackChoices);
                foreach($selected_feedback->feedbackChoices as $selected_choice){
                    $selected_feedback_choices[] = $selected_choice->feedback_options_id;
                }
            }

            // grab the detailed tree list of all feedbacks and options
            $feedback_nature = 'ORDER_DELIVERED';
            $options = FeedbackOption::where('is_active', 1)
                ->where('type', $feedback_nature)
                ->get();
            if($options->count()){
                foreach($options as $option){
                    $feedback_options[$option->feedback_type][] = [
                        'feedback_id' => $option->feedback_id,
                        'title_en' => $option->title_en,
                        'title_ar' => $option->title_ar,
                        'feedback_id' => $option->feedback_id,
                        'localized_title' => $option->localized_title,
                    ];
                }
            }
        }

        return view('order.new_view_exp', [
            'model' => $model,
            'feedback_options' => $feedback_options,
            'selected_feedback' => $selected_feedback,
            'selected_feedback_choices' => $selected_feedback_choices,
        ]);

    }

    public function cancelOrder(Request $request)
    {

        /** @var Order $model */
        $model = Order::where('userid', Auth::user()->id)
            ->where('deleted', false)
            ->whereNotIn('status', [
                Order::STATUS_PENDING_PAYMENT
            ])
            ->where('orderid', request()->get('id'))
            ->firstOrFail();

        $request->validate([
            'cancellation_reason' => [
                'required',
                Rule::in(CancellationReason::getList()),
            ],
            'comments' => 'required',
        ]);

        $cancellationReason = $request->post('cancellation_reason');
        $cancellationComment = $request->post('comments');

        $handler = new CancelOrderJob($model, $cancellationReason, $cancellationComment);
        $handler->handle();

        return back()->with('success', __('orders.order_cancelled'));
    }

    public function viewInvoice()
    {
        return $this->viewMobileInvoice(request()->get('id'));
    }

    public function viewMobileInvoice($id = null)
    {
        if (null == $id) {
            $id = request()->get('id');
        }

        /** @var Order $model */
        $model = Order::where('deleted', false)
            ->whereNotIn('status', [
                Order::STATUS_PENDING_PAYMENT
            ])
            ->where('orderid', $id)
            ->firstOrFail();

        $invoiceOrder = InvoiceOrder::where('orderid', $model->orderid)->first();
        $invoiceModel = Invoice::where('invoiceid', $invoiceOrder->invoiceid)->first();

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => storage_path('/tmp')
        ]);

        $mpdf->setFooter('{PAGENO}');

        if ($model->merchantIsCartlow()) {
            $view = view('order.invoice', [
                'model' => $model,
                'invoiceModel' => $invoiceModel
            ]);
        } else {
            $view = view('order.invoice-summary', [
                'model' => $model,
                'invoiceModel' => $invoiceModel
            ]);
        }

        $html = $view->render();

        $mpdf->WriteHTML($html);

        $mpdf->Output();
        exit;
    }
    public function viewB2BMobileInvoice($id = null)
    {
        if (null == $id) {
            $id = request()->get('id');
        }

        /** @var Order $model */
        $model = Order::where('order_type',2)
                        ->where('deleted', false)
                        ->where('orderid', $id)
                        ->firstOrFail();

        $invoiceOrder = InvoiceOrder::where('orderid', $model->orderid)->first();
        $invoiceModel = Invoice::where('invoiceid', $invoiceOrder->invoiceid)->first();
		$qrCode = $model->getQRCodeOfOrderLinkForGovt();

        /**
		 * Enabling Different Company Name and registrations
		 */
		$invoicingCompany = [
			'vascart'    => [
				'name' => 'VASCART GENERAL TRADING LLC',
				'vat'  => '100619720400003',
			],
			'cartlow_sa' => [
				'name' => 'Cartlow Trading Company',
				'vat'  => '300018724700003',
			],
		];
		$company          = [];
		if ($model->merchantIsCartlow()) {
			$company = ($model->merchant->id == 97693) ? $invoicingCompany['cartlow_sa'] : $invoicingCompany['vascart'];
		}

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => storage_path('/tmp')
        ]);

        $mpdf->setFooter('{PAGENO}');


        $view = view('order.b2b-invoice', [
            'model' => $model,
            'invoiceModel' => $invoiceModel,
            'company' => $company,
            'qrCode' =>$qrCode
        ]);

        $html = $view->render();

        $mpdf->WriteHTML($html);

        $mpdf->Output();
        exit;
    }
}
