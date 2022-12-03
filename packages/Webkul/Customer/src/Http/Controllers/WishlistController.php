<?php

namespace Webkul\Customer\Http\Controllers;

use Cart;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Customer\Repositories\WishlistRepository;
use Webkul\Product\Repositories\ProductRepository;

class WishlistController extends Controller
{
    /**
     * Contains route related configuration.
     
     * @var array
     */
    protected $_config;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Customer\Repositories\WishlistRepository  $wishlistRepository
     * @param  \Webkul\Product\Repositories\ProductRepository  $productRepository
     * @return void
     */
    public function __construct(
        protected WishlistRepository $wishlistRepository,
        protected ProductRepository $productRepository
    )
    {
        $this->_config = request('_config');
    }

    /**
     * Displays the listing resources if the customer having items in wishlist.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $customer = auth()->guard('customer')->user();

        if (! core()->getConfigData('general.content.shop.wishlist_option')) {
            abort(404);
        }

        return view($this->_config['view'], [
            'items'              => $this->wishlistRepository->getCustomerWishlist(),
            'isSharingEnabled'   => $this->isSharingEnabled(),
            'isWishlistShared'   => 0,
            'wishlistSharedLink' => $customer->getWishlistSharedLink()
        ]);
    }

    /**
     * Function to add item to the wishlist.
     *
     * @param  int  $productId
     * @return \Illuminate\Http\Response
     */
    public function add($productId)
    {
        $customer = auth()->guard('customer')->user();

        $product = $this->productRepository->find($productId);

        if (! $product) {
            session()->flash('error', trans('customer::app.product-removed'));

            return redirect()->back();
        } elseif (
            (! $product->status) 
            || (! $product->visible_individually)
        ) {
            abort(404);
        }

        $data = [
            'channel_id'  => core()->getCurrentChannel()->id,
            'product_id'  => $productId,
            'customer_id' => $customer->id,
        ];

        $wishlist = $this->wishlistRepository->findOneWhere($data);

        if (
            $product->parent
            && $product->parent->type !== 'configurable'
        ) {
            $product = $this->productRepository->find($product->parent_id);

            $data['product_id'] = $product->id;
        }

        if (! $wishlist) {
            $wishlist = $this->wishlistRepository->create($data);

            session()->flash('success', trans('customer::app.wishlist.success'));

            return redirect()->back();
        } else {
            $this->wishlistRepository->findOneWhere([
                'product_id' => $data['product_id']
            ])->delete();

            session()->flash('success', trans('customer::app.wishlist.removed'));

            return redirect()->back();
        }
    }

    /**
     * Share wishlist.
     *
     * @return \Illuminate\Http\Response
     */
    public function share()
    {
        $productIds = request()->product_ids;

        $productCount = request()->product_count;

        $selectedAll = false;

        if (count($productIds) == $productCount) {
            $selectedAll = true;
        }

        $customer = auth()->guard('customer')->user();

        if ($this->isSharingEnabled()) {
            $data = $this->validate(request(), [
                'shared' => 'required|boolean'
            ]);

            if ($productIds && $data['shared'] && ! $selectedAll) {
                $updateCounts = $customer->wishlist_items();

                $updateCounts->whereIn('product_id', $productIds);

                $updateCounts->update(['shared' => $data['shared']]);
            }

            if (! $selectedAll) {
                $notSharingProduct = $customer->wishlist_items();
                
                if ($productIds) {
                    $notSharingProduct->whereNotIn('product_id', $productIds);
                }

                $notSharingProduct->update(['shared' => 0]);
            }

            if ($selectedAll && $productIds) {
                $selectAllProduct = $customer->wishlist_items();

                $selectAllProduct->update(['shared' => 1]);

            }

            return response()->json([
                'isWishlistShared'   => $data['shared'] ? 1 : 0,
                'wishlistSharedLink' => $customer->getWishlistSharedLink($productIds)
            ]);
        }

        return response()->json([], 400);
    }

    /**
     * View of shared wishlist.
     *
     * @return \Illuminate\Http\Response
     */
    public function shared(CustomerRepository $customerRepository)
    {
        if (
            ! $this->isSharingEnabled()
            || ! request()->hasValidSignature()
            || ! core()->getConfigData('general.content.shop.wishlist_option')
        ) {
            abort(404);
        }

        $customer = $customerRepository->find(request()->get('id'));

        $items = $customer->wishlist_items()
        ->where('shared', 1);

        if (request()->get('product_ids')) {
            $items->whereIn('product_id', request()->get('product_ids'));
        }

        $items = $items->get();

        if (
            $customer
            && $items->isNotEmpty()
        ) {
            return view($this->_config['view'], compact('customer', 'items'));
        }

        /**
         * All remaining cases should be aborted with 404 page.
         */
        abort(404);
    }

    /**
     * Function to remove item to the wishlist.
     *
     * @param  int  $itemId
     * @return \Illuminate\Http\Response
     */
    public function remove($itemId)
    {
        $customer = auth()->guard('customer')->user();

        $customerWishlistItems = $customer->wishlist_items;
        $referer = strtok(request()->headers->get('referer'), '?');

        foreach ($customerWishlistItems as $customerWishlistItem) {
            if ($itemId == $customerWishlistItem->id) {
                $this->wishlistRepository->delete($itemId);

                session()->flash('success', trans('customer::app.wishlist.removed'));

                return redirect()->to($referer);
            }
        }

        session()->flash('error', trans('customer::app.wishlist.remove-fail'));

        return redirect()->back();
    }

    /**
     * Function to move item from wishlist to cart.
     *
     * @param  int  $itemId
     * @return \Illuminate\Http\Response
     */
    public function move($itemId)
    {
        $customer = auth()->guard('customer')->user();
        
        $wishlistItem = $this->wishlistRepository->findOneWhere([
            'id'          => $itemId,
            'customer_id' => $customer->id,
        ]);

        if (! $wishlistItem) {
            abort(404);
        }

        try {
            $result = Cart::moveToCart($wishlistItem);

            if ($result) {
                session()->flash('success', trans('shop::app.customer.account.wishlist.moved'));
            } else {
                session()->flash('info', trans('shop::app.checkout.cart.integrity.missing_options'));

                return redirect()->route('shop.productOrCategory.index', $wishlistItem->product->url_key);
            }

            return redirect()->back();
        } catch (\Exception $e) {
            report($e);

            session()->flash('warning', $e->getMessage());

            return redirect()->route('shop.productOrCategory.index',  $wishlistItem->product->url_key);
        }
    }

    /**
     * Function to remove all of the items items in the customer's wishlist.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeAll()
    {
        $customer = auth()->guard('customer')->user();

        foreach ($customer->wishlist_items as $wishlistItem) {
            $this->wishlistRepository->delete($wishlistItem->id);
        }

        session()->flash('success', trans('customer::app.wishlist.remove-all-success'));

        return redirect()->back();
    }

    /**
     * Is sharing enabled.
     *
     * @return bool
     */
    public function isSharingEnabled(): bool
    {
        return (bool) core()->getConfigData('customer.settings.wishlist.share');
    }
}
