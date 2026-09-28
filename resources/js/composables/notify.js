import { Notify } from 'quasar'
const page = usePage()

export const notifySuccess = () => {
  Notify.create({
    message: page.props.message,
    position: 'bottom-right',
    class: 'app-btn app-btn--positive',
    icon: 'check_circle_outline',
    timeout: 3000,
  })
}
