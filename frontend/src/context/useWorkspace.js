import { useContext } from 'react'
import { WorkspaceContext } from './workspace-context.js'

export function useWorkspace() {
  const value = useContext(WorkspaceContext)
  if (!value) throw new Error('useWorkspace must be used inside WorkspaceProvider')
  return value
}
