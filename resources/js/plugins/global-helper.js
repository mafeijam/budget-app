export default {
  install(app) {
    app.config.globalProperties.$eventBus = eventBus
  },
}
