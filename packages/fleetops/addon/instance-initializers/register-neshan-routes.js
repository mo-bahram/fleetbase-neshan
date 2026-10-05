export function initialize(owner) {
    const routeEngine = owner.lookup('service:route-engine');
    const neshanRoutes = owner.lookup('service:neshan-routes');

    if (routeEngine && neshanRoutes) {
        routeEngine.register('neshan', neshanRoutes, { display: true });
    }
}

export default {
    initialize,
};
