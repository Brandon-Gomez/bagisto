<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Customer\Repositories\WishlistRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Http\Resources\WishlistResource;

class WishlistController extends APIController
{
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
    ) {
    }

    /**
     * Displays the listing resources if the customer having items in wishlist.
     *
     * @return \Illuminate\View\View
     */
    public function index(): JsonResource
    {    
        $customer = auth()->guard('customer')->user();
        
        if (! core()->getConfigData('general.content.shop.wishlist_option')) {
            abort(404);
            
        }

        $deletedItemsCount = $this->removeInactiveItems();

        if ($deletedItemsCount) {
            session()->flash('info', trans('shop::app.customers.account.wishlist.product-removed'));
        }
    
        $items = $this->wishlistRepository->where([
            'channel_id'  => core()->getCurrentChannel()->id,
            'customer_id' => auth()->guard('customer')->user()->id,
        ])->get();

        return WishlistResource::collection($items);
    }

    /**
     * Removing inactive wishlist item.
     *
     * @return void|int
     */
    public function removeInactiveItems()
    {
        $customer = auth()->guard('customer')->user();

        $customer->load(['wishlist_items.product']);

        $inactiveItemIds = $customer->wishlist_items
            ->filter(fn ($item) => ! $item->product->status)
            ->pluck('product_id')
            ->toArray();

        return $customer->wishlist_items()
            ->whereIn('product_id', $inactiveItemIds)
            ->delete();
    }

    /**
     * Function to add item to the wishlist.
     *
     * @param  int  $productId
     * @return \Illuminate\Http\Response
     */
    public function store($productId)
    {
        $customer = auth()->guard('customer')->user();
        
        $product = $this->productRepository->find($productId);

        if (! $product) {
            return response()->json([
                'message'  => trans('customer::app.product-removed')
            ]);

        } elseif (
            (! $product->status)
            || (! $product->visible_individually)
        ) {
            return response()->json([
                'message'  => trans('shop::app.component.products.check-product-visibility')
            ]);
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

            return response()->json([
                'data'     => $wishlist,
                'message'  => trans('customer::app.wishlist.success')
            ]);

        } else {

            $this->wishlistRepository->findOneWhere([
                'product_id' => $data['product_id'],
            ])->delete();

            return response()->json([
                'message'  => trans('customer::app.wishlist.removed')
            ]);
        }
    }

    /**
     * Function to remove item to the wishlist.
     *
     * @param  int  $itemId
     * @return \Illuminate\Http\Response
     */
    public function destroy()
    {
        $itemId = request()->input('product_id');

        if ($itemId) {
            $data = $this->wishlistRepository->deleteWhere([
                'product_id' => $itemId,
            ]);

            $items = $this->wishlistRepository->get();

            return new JsonResource([
                'data'    => WishlistResource::collection($items),
                'message' => trans('shop::app.customers.account.wishlist.removed'),
            ]);
        }
    }

    /**
     * Function to move item from wishlist to cart.
     *
     * @param  int  $productId
     * @param  int  $itemId
     * @return \Illuminate\Http\Response
     */
    public function moveToCart($itemId)
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
                session()->flash('success', trans('shop::app.customers.account.wishlist.moved'));
            } else {
                session()->flash('info', trans('shop::app.customers.account.wishlist.missing_options'));

                return redirect()->route('shop.productOrCategory.index', $wishlistItem->product->url_key);
            }

            return redirect()->back();
        } catch (\Exception $e) {
            report($e);

            session()->flash('warning', $e->getMessage());

            return redirect()->route('shop.productOrCategory.index', $wishlistItem->product->url_key);
        }
    }

    /**
     * Function to remove all of the items items in the customer's wishlist.
     *
     * @return \Illuminate\Http\Response
     */
    public function massDestroy()
    {
        $customer = auth()->guard('customer')->user();

        foreach ($customer->wishlist_items as $wishlistItem) {
            $this->wishlistRepository->delete($wishlistItem->id);
        }

        session()->flash('success', trans('shop::app.customers.account.wishlist.remove-all-success'));

        return redirect()->back();
    }
}
