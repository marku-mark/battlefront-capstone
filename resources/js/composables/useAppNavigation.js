import {
    Boxes,
    ChartNoAxesCombined,
    LayoutDashboard,
    MapPin,
    MessageSquareText,
    PackageSearch,
    ReceiptText,
    ShoppingCart,
    UsersRound,
    UserRound,
} from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import CustomerController from '@/actions/App/Http/Controllers/Administration/CustomerController';
import ForecastingController from '@/actions/App/Http/Controllers/Administration/ForecastingController';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import AdministrationOrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import SalesReportController from '@/actions/App/Http/Controllers/Administration/SalesReportController';
import { dashboard } from '@/routes';
import { index as branchIndex } from '@/routes/branches';
import { index as cartIndex } from '@/routes/cart';
import { index as orderIndex } from '@/routes/orders';
import { index as productIndex } from '@/routes/products';
import { edit as editProfile } from '@/routes/profile';

export function useAppNavigation() {
    const page = usePage();
    const isAdministrator = computed(
        () => page.props.auth?.can?.accessAdministration === true,
    );
    const canUseCustomerCart = computed(
        () => page.props.auth?.can?.useCustomerCart === true,
    );
    const sectionLabel = computed(() =>
        isAdministrator.value ? 'Administration' : 'Customer',
    );
    const mainNavItems = computed(() => {
        const items = [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutDashboard,
            },
            {
                title: 'Branches',
                href: branchIndex(),
                icon: MapPin,
            },
        ];

        if (isAdministrator.value) {
            items.push({
                title: 'Catalog',
                href: ProductController.index(),
                icon: PackageSearch,
                activeRoutes: [
                    ProductController.index(),
                    CategoryController.index(),
                ],
            });
            items.push({
                title: 'Inventory',
                href: InventoryController.index(),
                icon: Boxes,
            });
            items.push({
                title: 'Orders',
                href: AdministrationOrderController.index(),
                icon: ReceiptText,
            });
            items.push({
                title: 'Sales reports',
                href: SalesReportController.index(),
                icon: ChartNoAxesCombined,
            });
            items.push({
                title: 'Forecasting',
                href: ForecastingController.index(),
                icon: ChartNoAxesCombined,
            });
            items.push({
                title: 'Customers',
                href: CustomerController.index(),
                icon: UsersRound,
            });
            items.push({
                title: 'Chatbot knowledge',
                href: ChatbotKnowledgeController.index(),
                icon: MessageSquareText,
                activeRoutes: [ChatbotKnowledgeController.index()],
            });
        } else {
            items.push({
                title: 'Products',
                href: productIndex(),
                icon: PackageSearch,
            });
        }

        if (canUseCustomerCart.value) {
            items.push({
                title: 'Orders',
                href: orderIndex(),
                icon: ReceiptText,
                activeRoutes: [orderIndex()],
            });
            items.push({
                title: 'Cart',
                href: cartIndex(),
                icon: ShoppingCart,
            });
        }

        items.push({
            title: 'Account',
            href: editProfile(),
            icon: UserRound,
        });

        return items;
    });

    return {
        isAdministrator,
        mainNavItems,
        sectionLabel,
    };
}
