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

// For a save made without a form to put the message in. A shortcut can be refused -- by
// every guard a dialog would have shown beside the field -- and it has to say which one,
// because the alternative is a click that changes nothing at all and looks broken.
export const notifyFailure = message => {
  Notify.create({
    message,
    position: 'bottom-right',
    class: 'app-btn app-btn--negative',
    icon: 'error_outline',
    timeout: 6000,
  })
}
