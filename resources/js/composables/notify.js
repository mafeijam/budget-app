import { Notify } from 'quasar'
const page = usePage()

export const notifySuccess = () => {
  Notify.create({
    message: page.props.message,
    position: 'bottom-right',
    color: 'teal-2',
    textColor: 'teal-10',
    icon: 'info',
  })
}
